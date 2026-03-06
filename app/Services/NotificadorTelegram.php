<?php

namespace App\Services;

use App\Exceptions\TelegramRateLimitException;
use App\Models\Configuracion;
use App\Models\Producto;
use App\Models\TelegramMensajeOferta;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Notificador de ofertas vía Telegram (Free y Premium).
 * Las ofertas se envían con captura de pantalla (Browsershot) de la página del producto, no con imagen del CDN.
 * Si la captura falla, se envía solo texto. Bajada histórica (≥30%) también usa Browsershot.
 * Toggle enviar_imagenes: si está desactivado, se envía solo texto.
 */
class NotificadorTelegram
{
    /**
     * Si la respuesta de la API de Telegram es 429 Too Many Requests, lanza excepción para que el Job reintente con release(30).
     */
    private function asegurarNoRateLimit(Response $response): void
    {
        if ($response->status() === 429) {
            throw new TelegramRateLimitException('Telegram API: 429 Too Many Requests. Reintentar más tarde.');
        }
    }

    /** Rango Gratis: solo ofertas entre este % y GRATIS_PORCENTAJE_MAX (inclusive) se envían al canal Gratis. */
    private const GRATIS_PORCENTAJE_MIN = 5.0;

    /** Rango Gratis: ofertas entre GRATIS_PORCENTAJE_MIN y este % (inclusive) se envían al canal Gratis. */
    private const GRATIS_PORCENTAJE_MAX = 29.99;

    /**
     * Devuelve a qué canales enviar la oferta según el % de ahorro.
     * Premium: 0% a 100%+ (todos los descuentos que notificamos).
     * Gratis: solo 5% a 29.99%.
     * Cada elemento es ['chat_id' => string, 'caption' => string] para usar la misma captura con caption por canal.
     *
     * @return array<int, array{chat_id: string, caption: string}>
     */
    private function destinosParaOferta(Producto $producto, float $porcentaje, bool $soloTexto = false): array
    {
        $chatPremium = config('services.telegram.chat_id_premium');
        $chatFree = config('services.telegram.chat_id_free');
        $chatFallback = config('services.telegram.chat_id');

        $destinos = [];

        if ((string) $chatPremium !== '') {
            $captionPremium = $this->construirCaption($producto, $porcentaje, true, $soloTexto);
            $destinos[] = ['chat_id' => (string) $chatPremium, 'caption' => $captionPremium];
        } elseif ((string) $chatFree === '' && (string) $chatFallback !== '') {
            $captionPremium = $this->construirCaption($producto, $porcentaje, true, $soloTexto);
            $destinos[] = ['chat_id' => (string) $chatFallback, 'caption' => $captionPremium];
        }

        if ($porcentaje >= self::GRATIS_PORCENTAJE_MIN && $porcentaje <= self::GRATIS_PORCENTAJE_MAX && (string) $chatFree !== '') {
            $captionGratis = $this->construirCaption($producto, $porcentaje, false, $soloTexto);
            $destinos[] = ['chat_id' => (string) $chatFree, 'caption' => $captionGratis];
        }

        return $destinos;
    }

    /** Indica si la oferta se considera "bomba" (≥ 30%) para enviar teaser al canal Free. */
    private function esOfertaBomba(float $porcentajeAhorro): bool
    {
        return $porcentajeAhorro >= 30.0;
    }

    /** Para bajada histórica: ≥30% → Premium; 10-29.9% → Gratis; <10% no se envía. */
    private function chatIdParaBajadaHistorica(float $porcentajeBajada): ?string
    {
        if ($porcentajeBajada >= 30.0) {
            $chat = config('services.telegram.chat_id_premium');
            return $chat !== null && (string) $chat !== '' ? (string) $chat : null;
        }
        if ($porcentajeBajada >= 10.0 && $porcentajeBajada < 30.0) {
            $chat = config('services.telegram.chat_id_free');
            return $chat !== null && (string) $chat !== '' ? (string) $chat : null;
        }
        return null;
    }

    /** Horas durante las cuales no se reenvía la misma oferta (producto + precio) a Telegram. */
    private const HORAS_ANTES_REENVIAR_OFERTA = 12;

    /**
     * Notifica una oferta: captura de pantalla (Browsershot) de la página del producto y la envía al canal
     * que corresponda (Premium o Gratis). Si la captura falla, se envía solo texto. No se usa imagen del CDN.
     * Evita duplicados: la misma oferta (producto + precio oferta) no se envía de nuevo en HORAS_ANTES_REENVIAR_OFERTA.
     */
    public function notificarOferta(Producto $producto): void
    {
        $porcentaje = $producto->porcentaje_ahorro !== null ? (float) $producto->porcentaje_ahorro : 0;
        $requiereAdicional = Configuracion::requiereDescuentoAdicional();

        if ($requiereAdicional && ! $producto->permite_descuento_adicional) {
            return;
        }

        $destinos = $this->destinosParaOferta($producto, $porcentaje);
        if ($destinos === []) {
            Log::warning('NotificadorTelegram: oferta NO enviada; ningún canal configurado. Revisa TELEGRAM_CHAT_ID_PREMIUM y TELEGRAM_CHAT_ID_FREE en .env y ejecuta telegram:verificar.', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
            ]);
            return;
        }

        $claveDuplicado = 'telegram_oferta_enviada_' . $producto->id . '_' . (string) ($producto->precio_oferta ?? '');
        if (Cache::has($claveDuplicado)) {
            Log::debug('NotificadorTelegram: oferta omitida (ya enviada recientemente)', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
            ]);
            return;
        }

        if (! Configuracion::enviarImagenes()) {
            $this->enviarOfertaSoloTextoADestinos($producto, $porcentaje, $destinos);
            Cache::put($claveDuplicado, true, now()->addHours(self::HORAS_ANTES_REENVIAR_OFERTA));
            return;
        }

        $token = config('services.telegram.token');
        if (empty($token)) {
            Log::debug('NotificadorTelegram: TELEGRAM_BOT_TOKEN no configurado.');
            return;
        }

        Log::info('NotificadorTelegram: oferta enviada', [
            'producto_id' => $producto->id,
            'sku_tienda' => $producto->sku_tienda,
            'porcentaje_ahorro' => $porcentaje,
            'destinos' => count($destinos),
        ]);

        $urlAfiliado = $producto->url_afiliado_completa ?? $producto->url_original;
        $this->enviarConCapturaOFallbackADestinos($token, $destinos, $producto, $urlAfiliado, 'NotificadorTelegram: producto sin URL para captura de oferta');

        // No lanzar después de enviar: evita que el Job reenvíe con enviarOfertaSoloTexto (doble mensaje).
        try {
            Cache::put($claveDuplicado, true, now()->addHours(self::HORAS_ANTES_REENVIAR_OFERTA));
            if ($this->esOfertaBomba($porcentaje)) {
                $this->enviarTeaserOfertaBombaAlCanalFree($porcentaje, $producto->categoria_origen ?? null);
            }
        } catch (\Throwable $e) {
            Log::warning('NotificadorTelegram: error tras enviar oferta (cache/teaser)', [
                'producto_id' => $producto->id,
                'mensaje' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envía la oferta solo texto a cada destino (cuando enviar_imagenes está desactivado).
     *
     * @param  array<int, array{chat_id: string, caption: string}>  $destinos
     */
    private function enviarOfertaSoloTextoADestinos(Producto $producto, float $porcentaje, array $destinos): void
    {
        $token = config('services.telegram.token');
        if (empty($token)) {
            return;
        }
        $urlAfiliado = $producto->url_afiliado_completa ?? $producto->url_original;
        foreach ($destinos as $destino) {
            $this->enviarMensajeSinFoto($token, $destino['chat_id'], $destino['caption'], $urlAfiliado, $producto->id);
        }
    }

    /**
     * Captura una vez y envía a cada destino (Premium y/o Gratis). Misma captura, caption por canal.
     *
     * @param  array<int, array{chat_id: string, caption: string}>  $destinos
     */
    private function enviarConCapturaOFallbackADestinos(string $token, array $destinos, Producto $producto, ?string $urlAfiliado, string $mensajeLogSinUrl): void
    {
        $urlPagina = $producto->url_original ?? $urlAfiliado;
        $captionFallback = "\n\n🖼️ (Captura no disponible)";

        if (empty($urlPagina) || ! str_starts_with($urlPagina, 'http')) {
            Log::warning($mensajeLogSinUrl, ['producto_id' => $producto->id, 'tienda' => $producto->tienda_origen]);
            foreach ($destinos as $destino) {
                $this->enviarMensajeSinFoto($token, $destino['chat_id'], $destino['caption'] . $captionFallback, $urlAfiliado, $producto->id);
            }
            return;
        }

        $contenidoCaptura = $this->capturarPantallaProductoConReintentos($urlPagina, $producto->id);
        if ($contenidoCaptura !== null && $contenidoCaptura !== '') {
            foreach ($destinos as $destino) {
                $this->enviarFotoConCaptura($token, $destino['chat_id'], $contenidoCaptura, $destino['caption'], $urlAfiliado, $producto->id);
            }
            return;
        }

        Log::info('NotificadorTelegram: fallback a solo texto (captura no disponible).', [
            'producto_id' => $producto->id,
            'url' => $urlPagina,
            'tienda' => $producto->tienda_origen,
        ]);
        foreach ($destinos as $destino) {
            $this->enviarMensajeSinFoto($token, $destino['chat_id'], $destino['caption'] . $captionFallback, $urlAfiliado, $producto->id);
        }
    }

    /**
     * Flujo único: captura Browsershot de la página del producto y envía foto o, si falla, solo texto (un solo chat).
     * Usado por bajada histórica.
     */
    private function enviarConCapturaOFallback(string $token, string $chatId, Producto $producto, string $caption, ?string $urlAfiliado, string $mensajeLogSinUrl): void
    {
        $this->enviarConCapturaOFallbackADestinos($token, [['chat_id' => $chatId, 'caption' => $caption]], $producto, $urlAfiliado, $mensajeLogSinUrl);
    }

    /**
     * Marketing FOMO: teaser dinámico al canal Free cuando la oferta es Premium.
     * No incluye nombre ni enlace del producto; usa categoría, % de ahorro y botón a TELEGRAM_PREMIUM_JOIN_URL.
     */
    private function enviarTeaserOfertaBombaAlCanalFree(float $porcentajeAhorro, ?string $categoria = null): void
    {
        $token = config('services.telegram.token');
        $chatIdFree = config('services.telegram.chat_id_free');
        $linkPremium = config('services.telegram.premium_join_url', '');

        if (empty($token) || empty($chatIdFree)) {
            return;
        }

        $categoriaLabel = trim((string) $categoria) !== '' ? $this->escaparHtml($categoria) : 'Ofertas';
        $pct = number_format($porcentajeAhorro, 0);
        $texto = "🚨 <b>¡OFERTA BOMBA DETECTADA en {$categoriaLabel}!</b> 🚨\n\n"
            . "Un producto de esta categoría acaba de bajar un <b>{$pct}%</b>. 😱\n\n"
            . "🔒 Este enlace es exclusivo para miembros Premium. ¡Aprovecha antes de que se agote!";

        $payload = [
            'chat_id' => $chatIdFree,
            'text' => $texto,
            'parse_mode' => 'HTML',
        ];
        if (! empty($linkPremium)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => [
                    [['text' => '⭐ ¡Ver Oferta Premium ahora!', 'url' => $linkPremium]],
                ],
            ]);
        }

        $response = Http::withOptions(['verify' => false])
            ->timeout(10)
            ->connectTimeout(5)
            ->asForm()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
        $this->asegurarNoRateLimit($response);
    }

    /**
     * Envía la oferta solo como texto (sin foto). Usado cuando enviar_imagenes está desactivado o como fallback.
     * Primero envía el mensaje al canal que corresponda; si es bomba, después el teaser al canal Free.
     */
    public function enviarOfertaSoloTexto(Producto $producto): void
    {
        $porcentaje = $producto->porcentaje_ahorro !== null ? (float) $producto->porcentaje_ahorro : 0;
        $requiereAdicional = Configuracion::requiereDescuentoAdicional();
        if ($requiereAdicional && ! $producto->permite_descuento_adicional) {
            return;
        }
        $destinos = $this->destinosParaOferta($producto, $porcentaje, true);
        if ($destinos === []) {
            return;
        }
        Log::info('NotificadorTelegram: oferta enviada (solo texto)', [
            'producto_id' => $producto->id,
            'sku_tienda' => $producto->sku_tienda,
            'porcentaje_ahorro' => $porcentaje,
            'destinos' => count($destinos),
        ]);
        $this->enviarOfertaSoloTextoADestinos($producto, $porcentaje, $destinos);
        if ($this->esOfertaBomba($porcentaje)) {
            $this->enviarTeaserOfertaBombaAlCanalFree($porcentaje, $producto->categoria_origen ?? null);
        }
    }

    /**
     * Construye el texto del mensaje según canal (Premium 💎 / Free 🔥) y si es solo texto (más emojis/separadores).
     */
    private function construirCaption(Producto $producto, float $porcentaje, bool $esPremium, bool $soloTexto): string
    {
        $precioOriginal = number_format((float) $producto->precio_original, 2);
        $precioOferta = $producto->precio_oferta !== null
            ? number_format((float) $producto->precio_oferta, 2)
            : $precioOriginal;
        $nombreLimpio = $this->escaparHtml(strip_tags((string) ($producto->nombre ?? '')));
        $tienda = $this->escaparHtml($producto->tienda_origen ?? '');
        $ahorro = number_format($porcentaje, 1);

        if ($esPremium) {
            $sep = $soloTexto ? "\n──────────────\n" : "\n";
            $lineas = [
                '💎 <b>OFERTA PREMIUM</b> 💎',
                $sep,
                '<b>' . $nombreLimpio . '</b>',
                '',
                '<b>Precio oferta: $' . $precioOferta . '</b>',
                'Precio original: <s>$' . $precioOriginal . '</s>',
                'Ahorro: <b>' . $ahorro . '%</b>',
                $soloTexto ? "\n──────────────" : '',
                $tienda,
            ];
        } else {
            $sep = $soloTexto ? "\n──────────────\n" : "\n";
            $lineas = [
                '🔥 <b>Nueva oferta</b> 🔥',
                $sep,
                '<b>' . $nombreLimpio . '</b>',
                '',
                '<b>Precio oferta: $' . $precioOferta . '</b>',
                'Precio original: <s>$' . $precioOriginal . '</s>',
                'Ahorro: <b>' . $ahorro . '%</b>',
                $soloTexto ? "\n──────────────" : '',
                $tienda,
            ];
        }

        return implode("\n", array_filter($lineas));
    }

    private function escaparHtml(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Envía la oferta según la calidad de la bajada, usando los IDs de canal del .env.
     * Regla de oro: si permite_descuento_adicional es false, no se envía a ningún canal.
     * - Bajada ≥30%: canal Premium, mensaje con captura Browsershot (formato "BAJADA HISTÓRICA").
     * - Bajada entre 10% y 29.9%: canal Gratis, mensaje solo texto (sin captura, para ahorrar recursos y motivar Premium).
     * - Bajada &lt;10%: no se envía nada.
     *
     * @param  float  $precioAyer  Precio anterior (para el caption).
     * @param  float  $precioHoy  Precio actual (para el caption).
     */
    public function enviarOfertaSegunCalidad(Producto $producto, float $bajada, float $precioAyer, float $precioHoy): void
    {
        if (! $producto->permite_descuento_adicional) {
            return;
        }

        if ($bajada < 10) {
            return;
        }

        // Evitar duplicado: si ya se envió esta oferta por el flujo normal (EnviarOfertaTelegramJob), no enviar de nuevo como "bajada".
        $claveDuplicado = 'telegram_oferta_enviada_' . $producto->id . '_' . (string) ($producto->precio_oferta ?? '');
        if (Cache::has($claveDuplicado)) {
            Log::debug('NotificadorTelegram: bajada omitida (oferta ya enviada por rastreo)', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
            ]);
            return;
        }

        $token = config('services.telegram.token');
        if (empty($token)) {
            Log::debug('NotificadorTelegram: TELEGRAM_BOT_TOKEN no configurado.');
            return;
        }

        if ($bajada >= 30) {
            $idCanal = config('services.telegram.chat_id_premium');
            if ($idCanal === null || $idCanal === '') {
                Log::debug('NotificadorTelegram: bajada ≥30% pero TELEGRAM_CHAT_ID_PREMIUM no configurado.');
                return;
            }
            $this->notificarBajadaHistoricaConCaptura($producto, $precioAyer, $precioHoy, (string) $idCanal);
            Cache::put($claveDuplicado, true, now()->addHours(self::HORAS_ANTES_REENVIAR_OFERTA));
            Log::info('NotificadorTelegram: oferta de bajada enviada a canal Premium', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
                'bajada_porcentaje' => round($bajada, 1),
            ]);
            return;
        }

        if ($bajada >= 10 && $bajada < 30) {
            $idCanal = config('services.telegram.chat_id_free');
            if ($idCanal === null || $idCanal === '') {
                Log::debug('NotificadorTelegram: bajada 10–29.9% pero TELEGRAM_CHAT_ID_FREE no configurado.');
                return;
            }
            $caption = $this->construirCaptionBajadaHistorica($producto, $precioAyer, $precioHoy, $bajada);
            $urlAfiliado = $producto->url_afiliado_completa ?? $producto->url_original;
            $this->enviarMensajeSinFoto($token, (string) $idCanal, $caption, $urlAfiliado, $producto->id);
            Cache::put($claveDuplicado, true, now()->addHours(self::HORAS_ANTES_REENVIAR_OFERTA));
            Log::info('NotificadorTelegram: oferta de bajada enviada a canal Gratis', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
                'bajada_porcentaje' => round($bajada, 1),
            ]);
        }
    }

    /**
     * Distribuye una oferta de bajada de precio al canal correspondiente (Gratis o Premium).
     * Reglas: no enviar si el producto no permite descuento adicional; ≥30% o "Error de precio" → Premium;
     * entre 10% y 29.9% → Gratis; &lt;10% no se notifica.
     */
    public function distribuirOferta(Producto $producto, float $precioAyer, float $precioHoy): void
    {
        if (! $producto->permite_descuento_adicional) {
            return;
        }

        $porcentajeBajada = $precioAyer > 0
            ? (($precioAyer - $precioHoy) / $precioAyer) * 100
            : 0.0;

        $esErrorPrecio = (bool) ($producto->es_error_precio ?? false);

        if ($porcentajeBajada >= 30 || $esErrorPrecio) {
            $idCanal = config('services.telegram.chat_id_premium');
        } elseif ($porcentajeBajada >= 10 && $porcentajeBajada < 30) {
            $idCanal = config('services.telegram.chat_id_free');
        } else {
            return;
        }

        if ($idCanal === null || $idCanal === '') {
            Log::debug('NotificadorTelegram: canal no configurado para distribuirOferta.');
            return;
        }

        $this->notificarBajadaHistoricaConCaptura($producto, $precioAyer, $precioHoy, $idCanal);
    }

    /**
     * Notifica una bajada histórica de precio con captura de pantalla de la página del producto.
     * Si se pasa $chatId se usa ese canal; si no, se resuelve con chatIdParaBajadaHistorica (≥30% Premium, 10-29.9% Gratis).
     */
    public function notificarBajadaHistoricaConCaptura(Producto $producto, float $precioAyer, float $precioHoy, ?string $chatId = null): void
    {
        $token = config('services.telegram.token');
        $porcentajeBajada = $precioAyer > 0
            ? (($precioAyer - $precioHoy) / $precioAyer) * 100
            : 0.0;

        if ($chatId === null || $chatId === '') {
            $chatId = $this->chatIdParaBajadaHistorica($porcentajeBajada);
        }

        if (empty($token) || $chatId === null || $chatId === '') {
            Log::debug('NotificadorTelegram: token o chat_id no configurados para bajada histórica.');
            return;
        }

        $caption = $this->construirCaptionBajadaHistorica($producto, $precioAyer, $precioHoy, $porcentajeBajada);
        $urlAfiliado = $producto->url_afiliado_completa ?? $producto->url_original;
        $this->enviarConCapturaOFallback(
            $token,
            $chatId,
            $producto,
            $caption,
            $urlAfiliado,
            'NotificadorTelegram: producto sin URL para captura de bajada histórica'
        );
    }

    /**
     * Construye el texto del mensaje para bajada histórica: Precio Ayer vs Precio Hoy y % de bajada.
     */
    private function construirCaptionBajadaHistorica(Producto $producto, float $precioAyer, float $precioHoy, float $porcentajeBajada): string
    {
        $nombreLimpio = $this->escaparHtml(strip_tags((string) ($producto->nombre ?? '')));
        $tienda = $this->escaparHtml($producto->tienda_origen ?? '');
        $lineas = [
            '📉 <b>BAJADA HISTÓRICA</b> 📉',
            '',
            '<b>' . $nombreLimpio . '</b>',
            '',
            'Precio Ayer: <s>$' . number_format($precioAyer, 2) . '</s>',
            'Precio Hoy: <b>$' . number_format($precioHoy, 2) . '</b>',
            'Bajada: <b>-' . number_format($porcentajeBajada, 1) . '%</b>',
            '',
            $tienda,
        ];

        return implode("\n", $lineas);
    }

    /**
     * Inyecta CSS en la página para ocultar modales, backdrops y widget de chat
     * antes de la captura (addStyleTag). La captura queda más limpia y profesional.
     */
    private function aplicarEstiloOcultarElementos(Browsershot $shot): void
    {
        $css = config('browsershot.css_ocultar_elementos');
        if ($css !== null && $css !== '') {
            $shot->setOption('addStyleTag', json_encode(['content' => $css]));
        }
    }

    /**
     * Configura Browsershot para cerrar hasta 5 popups (modales) antes de la captura.
     * Aplica a todas las tiendas (Coppel, Calimax, etc.). Espera un poco a que los modales
     * se rendericen (p. ej. "Ciudad de entrega" de Coppel), luego busca botones "Sí", "Aceptar",
     * "Cerrar", la X de cierre, etc., y hace clic hasta 5 veces o hasta que no quede ninguno.
     * Importante: solo se usa una llamada a delay() porque Browsershot tiene una sola opción
     * "delay"; la espera tras el último clic se hace dentro del script (waitMs).
     */
    private function aplicarCierrePopups(Browsershot $shot): void
    {
        $delayInicial = (int) config('browsershot.delay_antes_popups', 2500);
        $delayTrasCierre = (int) config('browsershot.delay_tras_cierre_popup', 800);

        $shot->delay($delayInicial);

        $shot->waitForFunction(
            '() => { const waitMs = (ms) => { const d = Date.now() + ms; while (Date.now() < d) {} }; window.__popupsCerrados = window.__popupsCerrados || 0; if (window.__popupsCerrados >= 5) { waitMs(' . $delayTrasCierre . '); return true; } const norm = s => (s || "").trim().replace(/\\s+/g, " "); const textos = ["Sí", "Aceptar", "Cerrar", "No gracias", "OK", "Entendido", "Continuar"]; const root = document.querySelector("[role=dialog], [role=alertdialog], .modal, [class*=\\"Modal\\"], [class*=\\"modal\\"]") || document.body; const candidates = root.querySelectorAll("button, [role=\\"button\\"], a, [aria-label]"); for (const el of candidates) { if (!el.offsetParent) continue; const t = norm(el.textContent); const aria = (el.getAttribute("aria-label") || "").trim(); const isClose = /cerrar|close/i.test(aria) || (el.textContent && el.textContent.length < 4 && (el.textContent.includes("×") || el.textContent.includes("X"))); const match = textos.some(txt => t === txt || t.startsWith(txt)) || isClose; if (match) { el.click(); window.__popupsCerrados++; return false; } } waitMs(' . $delayTrasCierre . '); return true; }',
            null,
            10000
        );
    }

    private const MAX_REINTENTOS_CAPTURA = 5;

    /** Segundos de espera entre reintentos de captura (da tiempo a que Chromium cierre bien). */
    private const SEGUNDOS_ENTRE_REINTENTOS = 3;

    /**
     * Captura con reintentos, ejecutando solo una captura a la vez en el sistema (lock).
     * Evita que varios jobs lancen Chromium a la vez y provoquen el patrón "2 con imagen, 1 sin".
     */
    private function capturarPantallaProductoConReintentos(string $urlPagina, int $productoId): ?string
    {
        $lock = Cache::lock('browsershot_capture', 180);

        try {
            $resultado = $lock->block(120, function () use ($urlPagina, $productoId) {
                return $this->ejecutarCapturaConReintentos($urlPagina, $productoId);
            });
        } finally {
            optional($lock)->release();
        }

        return $resultado;
    }

    /**
     * Intenta capturar hasta MAX_REINTENTOS_CAPTURA veces.
     * Solo se llama bajo el lock de browsershot_capture.
     */
    private function ejecutarCapturaConReintentos(string $urlPagina, int $productoId): ?string
    {
        $ultimoIntentos = 0;
        for ($intento = 1; $intento <= self::MAX_REINTENTOS_CAPTURA; $intento++) {
            $contenido = $this->capturarPantallaProducto($urlPagina, $productoId);
            if ($contenido !== null && $contenido !== '') {
                if ($intento > 1) {
                    Log::info('NotificadorTelegram: captura OK en reintento', [
                        'producto_id' => $productoId,
                        'intento' => $intento,
                    ]);
                }
                return $contenido;
            }
            $ultimoIntentos = $intento;
            if ($intento < self::MAX_REINTENTOS_CAPTURA) {
                sleep(self::SEGUNDOS_ENTRE_REINTENTOS);
            }
        }
        Log::warning('NotificadorTelegram: captura falló tras todos los reintentos', [
            'producto_id' => $productoId,
            'intentos' => $ultimoIntentos,
        ]);
        return null;
    }

    /**
     * Captura de pantalla de la URL del producto con Browsershot (Puppeteer).
     * Para Calimax (VTEX) se usa más timeout y User-Agent real para que la página cargue y no bloquee headless.
     * Devuelve el contenido binario de la imagen o null si hay timeout/bloqueo/error.
     */
    private function capturarPantallaProducto(string $urlPagina, int $productoId): ?string
    {
        $timeout = config('browsershot.timeout', 45);
        $ancho = config('browsershot.ancho', 1280);
        $alto = config('browsershot.alto', 800);
        $esCalimax = str_contains($urlPagina, 'calimax.com.mx');
        if ($esCalimax) {
            $timeout = max($timeout, 45);
            Log::info('NotificadorTelegram: intentando captura Browsershot para Calimax', [
                'producto_id' => $productoId,
                'url' => $urlPagina,
            ]);
        }
        $rutaTemp = storage_path('app/temp/captura-producto-' . $productoId . '-' . (getmypid() ?: 0) . '-' . uniqid('', true) . '.png');

        try {
            if (! is_dir(dirname($rutaTemp))) {
                @mkdir(dirname($rutaTemp), 0755, true);
            }

            $shot = Browsershot::url($urlPagina)
                ->noSandbox()
                ->addChromiumArguments([0 => 'disable-setuid-sandbox']) // Crucial para Linux (VPS/servidor sin display)
                ->timeout($timeout)
                ->windowSize($ancho, $alto);

            // Chromium del sistema: solo si está definido y es ejecutable (evita usar ruta rota que rompa capturas).
            $chromePath = trim((string) config('browsershot.chrome_path', ''));
            if ($chromePath !== '' && is_executable($chromePath)) {
                $shot->setChromePath($chromePath);
            }

            // Calimax/VTEX: User-Agent de navegador real para reducir bloqueos a headless.
            if ($esCalimax) {
                $shot->userAgent(
                    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
                );
            }

            $this->aplicarEstiloOcultarElementos($shot);
            $this->aplicarCierrePopups($shot);

            $shot->save($rutaTemp);

            if (! is_file($rutaTemp) || filesize($rutaTemp) === 0) {
                return null;
            }

            $contenido = file_get_contents($rutaTemp);
            @unlink($rutaTemp);
            if ($esCalimax) {
                Log::info('NotificadorTelegram: captura Browsershot Calimax OK', ['producto_id' => $productoId]);
            }

            return $contenido !== false ? $contenido : null;
        } catch (ProcessTimedOutException $e) {
            Log::warning('NotificadorTelegram: Browsershot timeout al capturar producto', [
                'url' => $urlPagina,
                'producto_id' => $productoId,
                'timeout' => $timeout,
                'es_calimax' => $esCalimax,
            ]);
            @unlink($rutaTemp);
            return null;
        } catch (CouldNotTakeBrowsershot $e) {
            Log::warning('NotificadorTelegram: Browsershot no pudo tomar captura', [
                'url' => $urlPagina,
                'producto_id' => $productoId,
                'error' => $e->getMessage(),
            ]);
            @unlink($rutaTemp);
            return null;
        } catch (\Throwable $e) {
            Log::warning('NotificadorTelegram: excepción en Browsershot', [
                'url' => $urlPagina,
                'producto_id' => $productoId,
                'error' => $e->getMessage(),
            ]);
            if (is_file($rutaTemp)) {
                @unlink($rutaTemp);
            }
            return null;
        }
    }

    /**
     * Envía la foto (captura) a Telegram. Si se pasa $productoId, guarda el message_id para limpieza posterior.
     */
    private function enviarFotoConCaptura(string $token, string $chatId, string $contenidoImagen, string $caption, ?string $urlAfiliado, ?int $productoId = null): void
    {
        $urlApi = "https://api.telegram.org/bot{$token}/sendPhoto";
        $payload = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];
        if (! empty($urlAfiliado)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => [
                    [['text' => 'Ver en Tienda', 'url' => $urlAfiliado]],
                ],
            ]);
        }

        $response = Http::withOptions(['verify' => false])
            ->timeout(20)
            ->connectTimeout(5)
            ->attach('photo', $contenidoImagen, 'captura-oferta.png')
            ->post($urlApi, $payload);

        $this->asegurarNoRateLimit($response);
        if (! $response->successful()) {
            Log::error('NotificadorTelegram: fallo sendPhoto (bajada histórica)', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return;
        }
        $messageId = $response->json('result.message_id');
        if ($messageId !== null && is_numeric($messageId)) {
            $this->registrarMensajeOferta($chatId, (int) $messageId, $productoId);
        }
    }

    /**
     * Envía solo texto (sin foto) con caption y botón "Ver en Tienda".
     * Si se pasa $productoId, guarda el message_id para limpieza posterior (solo ofertas).
     */
    private function enviarMensajeSinFoto(string $token, string $chatId, string $caption, ?string $urlAfiliado, ?int $productoId = null): void
    {
        $urlApi = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = [
            'chat_id' => $chatId,
            'text' => $caption,
            'parse_mode' => 'HTML',
        ];
        if (! empty($urlAfiliado)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => [
                    [['text' => 'Ver en Tienda', 'url' => $urlAfiliado]],
                ],
            ]);
        }

        $response = Http::withOptions(['verify' => false])
            ->timeout(10)
            ->connectTimeout(5)
            ->asForm()
            ->post($urlApi, $payload);

        $this->asegurarNoRateLimit($response);
        if (! $response->successful()) {
            Log::error('NotificadorTelegram: fallo envío sendMessage (sin foto)', [
                'status' => $response->status(),
                'body' => $response->body(),
                'json' => $response->json(),
            ]);
            return;
        }
        $messageId = $response->json('result.message_id');
        if ($messageId !== null && is_numeric($messageId) && $productoId !== null) {
            $this->registrarMensajeOferta($chatId, (int) $messageId, $productoId);
        }
    }

    /**
     * Guarda el message_id de una oferta enviada para poder borrarla después de 24 h.
     */
    private function registrarMensajeOferta(string $chatId, int $messageId, ?int $productoId = null): void
    {
        try {
            TelegramMensajeOferta::create([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'producto_id' => $productoId,
                'enviado_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotificadorTelegram: no se pudo registrar message_id para limpieza', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envía un mensaje de texto simple al chat configurado (p. ej. "Iniciando rastreo de Coppel...").
     */
    public function enviarMensajeSimple(string $texto): void
    {
        $chatId = config('services.telegram.chat_id');
        $this->enviarMensajeAChat($chatId !== null && $chatId !== '' ? (string) $chatId : null, $texto);
    }

    /**
     * Envía un mensaje de texto a un chat_id concreto (para resúmenes por canal).
     *
     * @param  string|null  $parseMode  'HTML' para formato bold, etc.; null para texto plano.
     */
    private function enviarMensajeAChat(?string $chatId, string $texto, ?string $parseMode = null): void
    {
        $token = config('services.telegram.token');
        if (empty($token) || $chatId === null || $chatId === '') {
            return;
        }
        $urlApi = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = ['chat_id' => $chatId, 'text' => $texto];
        if ($parseMode !== null && $parseMode !== '') {
            $payload['parse_mode'] = $parseMode;
        }
        $response = Http::withOptions(['verify' => false])
            ->timeout(10)
            ->connectTimeout(5)
            ->asForm()
            ->post($urlApi, $payload);
        $this->asegurarNoRateLimit($response);
        if (! $response->successful()) {
            Log::error('NotificadorTelegram: fallo envío mensaje a chat', [
                'chat_id' => $chatId,
                'status' => $response->status(),
            ]);
        }
    }

    /**
     * Envía al canal Premium un resumen corto al finalizar el rastreo (ej. "🏁 Rastreo de Calimax finalizado. 48 productos procesados, 32 ofertas enviadas").
     */
    public function enviarResumenFinalRastreo(string $tiendaOrigen, int $productosProcesados, int $ofertasEnviadas): void
    {
        $chatId = config('services.telegram.chat_id_premium');
        if ($chatId === null || $chatId === '') {
            return;
        }
        $texto = "🏁 <b>Rastreo de {$tiendaOrigen} finalizado.</b>\n\n"
            . "{$productosProcesados} productos procesados, {$ofertasEnviadas} ofertas enviadas.";
        $this->enviarMensajeAChat((string) $chatId, $texto, 'HTML');
    }

    /**
     * Envía a cada canal (Premium / Normal) un resumen de cuántas ofertas recibirán.
     * Premium = ≥ umbral % (ej. 20%); Normal = resto con descuento suficiente (ej. 10–19%).
     *
     * @param  Collection<int, Producto>  $productos
     */
    public function enviarResumenOfertasPorCanal(Collection $productos, string $tiendaOrigen): void
    {
        if ($productos->isEmpty()) {
            return;
        }
        $umbralPremium = Configuracion::porcentajeMinimoParaPremium();
        $minimo = Configuracion::porcentajeMinimoNotificacion();
        $premium = $productos->filter(function (Producto $p) use ($umbralPremium, $minimo): bool {
            $porcentaje = (float) ($p->porcentaje_ahorro ?? 0);
            return $porcentaje >= $umbralPremium || $porcentaje < $minimo;
        })->count();
        $normales = $productos->count() - $premium;

        $chatPremium = config('services.telegram.chat_id_premium');
        $chatFree = config('services.telegram.chat_id_free');
        if ($premium > 0 && $chatPremium !== null && $chatPremium !== '') {
            $this->enviarMensajeAChat((string) $chatPremium, "💎 <b>{$tiendaOrigen}: Resumen</b>\n\nVas a recibir <b>{$premium} ofertas Premium</b> (≥{$umbralPremium}% descuento).", 'HTML');
        }
        if ($normales > 0 && $chatFree !== null && $chatFree !== '') {
            $this->enviarMensajeAChat((string) $chatFree, "🔥 <b>{$tiendaOrigen}: Resumen</b>\n\nVas a recibir <b>{$normales} ofertas</b> para clientes normales (descuento &lt;{$umbralPremium}%).", 'HTML');
        }
    }
}
