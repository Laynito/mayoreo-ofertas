<?php

namespace App\Services;

use App\Exceptions\TelegramRateLimitException;
use App\Models\Configuracion;
use App\Models\Producto;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Notificador de ofertas vía Telegram (Free y Premium).
 * Canal Premium recibe todas las ofertas con descuento real (0%+); canal Free solo 10–19%.
 * ≥20% → Premium (y teaser al canal Free); 10–19% → Free; 0–9% → solo Premium.
 * Toggle enviar_imagenes: si está desactivado, se envía solo texto (evita 400 de Coppel).
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

    /**
     * Resuelve el chat_id según el % de ahorro:
     * ≥ umbral Premium (ej. 20%) → Premium; entre mínimo y umbral-1 (ej. 10–19%) → Free;
     * < mínimo (ej. 0–9%) → Premium (canal premium recibe todas las ofertas).
     * Fallback a services.telegram.chat_id si free/premium no están definidos.
     */
    private function chatIdParaOferta(float $porcentajeAhorro): ?string
    {
        $umbralPremium = Configuracion::porcentajeMinimoParaPremium();
        $minimo = Configuracion::porcentajeMinimoNotificacion();

        $esPremium = $porcentajeAhorro >= $umbralPremium || $porcentajeAhorro < $minimo;

        $chatId = $esPremium
            ? config('services.telegram.chat_id_premium')
            : config('services.telegram.chat_id_free');

        if ($chatId !== null && $chatId !== '') {
            return (string) $chatId;
        }

        return config('services.telegram.chat_id') ? (string) config('services.telegram.chat_id') : null;
    }

    /** Indica si la oferta se considera "bomba" (≥ umbral Premium, ej. 20%) para enviar teaser al canal Free. */
    private function esOfertaPremium(float $porcentajeAhorro): bool
    {
        return $porcentajeAhorro >= Configuracion::porcentajeMinimoParaPremium();
    }

    /** Indica si esta oferta se envía al canal Premium (≥20% o <10%; canal Free solo 10–19%). */
    private function esEnviadoACanalPremium(float $porcentajeAhorro): bool
    {
        $umbral = Configuracion::porcentajeMinimoParaPremium();
        $minimo = Configuracion::porcentajeMinimoNotificacion();
        return $porcentajeAhorro >= $umbral || $porcentajeAhorro < $minimo;
    }

    /**
     * Notifica una oferta al canal que corresponda (Free 10–19%, Premium ≥20%).
     * Si enviar_imagenes está desactivado, envía solo texto. Si la oferta es Premium, envía teaser al canal Free.
     */
    public function notificarOferta(Producto $producto): void
    {
        $porcentaje = $producto->porcentaje_ahorro !== null ? (float) $producto->porcentaje_ahorro : 0;
        $requiereAdicional = Configuracion::requiereDescuentoAdicional();

        if ($requiereAdicional && ! $producto->permite_descuento_adicional) {
            return;
        }

        $chatId = $this->chatIdParaOferta($porcentaje);
        if ($chatId === null || $chatId === '') {
            return;
        }

        if (! Configuracion::enviarImagenes()) {
            $this->enviarOfertaSoloTexto($producto);

            return;
        }

        $token = config('services.telegram.token');
        if (empty($token)) {
            Log::debug('NotificadorTelegram: TELEGRAM_BOT_TOKEN o chat_id (FREE/PREMIUM/CHAT_ID) no configurados.');

            return;
        }

        $esCanalPremium = $this->esEnviadoACanalPremium($porcentaje);
        $esOfertaBomba = $this->esOfertaPremium($porcentaje);
        Log::info('NotificadorTelegram: oferta enviada a canal ' . ($esCanalPremium ? 'Premium' : 'Free'), [
            'producto_id' => $producto->id,
            'sku_tienda' => $producto->sku_tienda,
            'porcentaje_ahorro' => $porcentaje,
        ]);

        if ($esOfertaBomba) {
            $this->enviarTeaserOfertaBombaAlCanalFree($porcentaje, $producto->categoria_origen ?? null);
        }

        $urlAfiliado = $producto->url_afiliado_completa ?? $producto->url_original;
        $caption = $this->construirCaption($producto, $porcentaje, $esCanalPremium, false);
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

        if (empty($producto->imagen_url)) {
            $this->enviarMensajeSinFoto($token, $chatId, $caption, $urlAfiliado);

            return;
        }

        $imagenContenido = $this->descargarImagen($producto->imagen_url);
        if ($imagenContenido === null) {
            Log::warning('NotificadorTelegram: no se pudo descargar imagen, reenviando solo texto', [
                'producto_id' => $producto->id,
                'imagen_url' => $producto->imagen_url,
            ]);
            $captionFallback = $caption . "\n\n🖼️ (Imagen no disponible temporalmente)";
            $this->enviarMensajeSinFoto($token, $chatId, $captionFallback, $urlAfiliado);

            return;
        }

        $nombreArchivo = $this->nombreArchivoImagen($producto->imagen_url);

        try {
            $response = Http::withOptions(['verify' => false])
                ->timeout(15)
                ->connectTimeout(5)
                ->attach('photo', $imagenContenido, $nombreArchivo)
                ->post($urlApi, $payload);

            $this->asegurarNoRateLimit($response);
            if (! $response->successful()) {
                throw new \RuntimeException(
                    'sendPhoto failed: ' . $response->status() . ' ' . $response->body()
                );
            }
        } catch (TelegramRateLimitException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('NotificadorTelegram: fallo sendPhoto, reenviando solo texto', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
                'error' => $e->getMessage(),
            ]);
            $captionFallback = $caption . "\n\n🖼️ (Imagen no disponible temporalmente)";
            $this->enviarMensajeSinFoto($token, $chatId, $captionFallback, $urlAfiliado);
        }
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
     * Si la oferta es Premium, envía también el teaser al canal Free.
     */
    public function enviarOfertaSoloTexto(Producto $producto): void
    {
        $porcentaje = $producto->porcentaje_ahorro !== null ? (float) $producto->porcentaje_ahorro : 0;
        $requiereAdicional = Configuracion::requiereDescuentoAdicional();
        if ($requiereAdicional && ! $producto->permite_descuento_adicional) {
            return;
        }
        $chatId = $this->chatIdParaOferta($porcentaje);
        if ($chatId === null || $chatId === '') {
            return;
        }
        $esCanalPremium = $this->esEnviadoACanalPremium($porcentaje);
        $esOfertaBomba = $this->esOfertaPremium($porcentaje);
        if ($esOfertaBomba) {
            $this->enviarTeaserOfertaBombaAlCanalFree($porcentaje, $producto->categoria_origen ?? null);
        }
        $token = config('services.telegram.token');
        if (empty($token)) {
            return;
        }
        Log::info('NotificadorTelegram: oferta enviada a canal ' . ($esCanalPremium ? 'Premium' : 'Free') . ' (solo texto)', [
            'producto_id' => $producto->id,
            'sku_tienda' => $producto->sku_tienda,
            'porcentaje_ahorro' => $porcentaje,
        ]);
        $caption = $this->construirCaption($producto, $porcentaje, $esCanalPremium, true);
        $urlAfiliado = $producto->url_afiliado_completa ?? $producto->url_original;
        $this->enviarMensajeSinFoto($token, $chatId, $caption, $urlAfiliado);
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

        $token = config('services.telegram.token');
        if (empty($token)) {
            Log::debug('NotificadorTelegram: TELEGRAM_BOT_TOKEN no configurado.');
            return;
        }

        if ($bajada >= 30) {
            $idCanal = config('services.telegram.chat_id_premium');
            if ($idCanal !== null && $idCanal !== '') {
                $this->notificarBajadaHistoricaConCaptura($producto, $precioAyer, $precioHoy, (string) $idCanal);
            }
            return;
        }

        if ($bajada >= 10 && $bajada < 30) {
            $idCanal = config('services.telegram.chat_id_free');
            if ($idCanal === null || $idCanal === '') {
                return;
            }
            $caption = $this->construirCaptionBajadaHistorica($producto, $precioAyer, $precioHoy, $bajada);
            $urlAfiliado = $producto->url_afiliado_completa ?? $producto->url_original;
            $this->enviarMensajeBajadaSoloTextoCanalFree($token, (string) $idCanal, $caption, $urlAfiliado);
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
     * Si se pasa $chatId se usa ese canal; si no, se resuelve con chatIdParaOferta según %.
     */
    public function notificarBajadaHistoricaConCaptura(Producto $producto, float $precioAyer, float $precioHoy, ?string $chatId = null): void
    {
        $token = config('services.telegram.token');
        $porcentajeBajada = $precioAyer > 0
            ? (($precioAyer - $precioHoy) / $precioAyer) * 100
            : 0.0;

        if ($chatId === null || $chatId === '') {
            $chatId = $this->chatIdParaOferta($porcentajeBajada);
        }

        if (empty($token) || $chatId === null || $chatId === '') {
            Log::debug('NotificadorTelegram: token o chat_id no configurados para bajada histórica.');
            return;
        }

        $caption = $this->construirCaptionBajadaHistorica($producto, $precioAyer, $precioHoy, $porcentajeBajada);
        $urlAfiliado = $producto->url_afiliado_completa ?? $producto->url_original;
        $urlPagina = $producto->url_original ?? $urlAfiliado;

        if (empty($urlPagina) || ! str_starts_with($urlPagina, 'http')) {
            Log::warning('NotificadorTelegram: producto sin URL para captura de bajada histórica', [
                'producto_id' => $producto->id,
            ]);
            $this->enviarMensajeBajadaSoloTexto($token, $chatId, $caption, $urlAfiliado);
            return;
        }

        $contenidoImagen = $this->capturarPantallaProducto($urlPagina, $producto->id);
        if ($contenidoImagen !== null && $contenidoImagen !== '') {
            $this->enviarFotoConCaptura($token, $chatId, $contenidoImagen, $caption, $urlAfiliado);
        } else {
            Log::info('NotificadorTelegram: fallback a solo texto para bajada histórica (captura no disponible).');
            $this->enviarMensajeBajadaSoloTexto($token, $chatId, $caption, $urlAfiliado);
        }
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

    /**
     * Captura de pantalla de la URL del producto con Browsershot (Puppeteer).
     * Devuelve el contenido binario de la imagen o null si hay timeout/bloqueo/error.
     */
    private function capturarPantallaProducto(string $urlPagina, int $productoId): ?string
    {
        $timeout = config('browsershot.timeout', 30);
        $ancho = config('browsershot.ancho', 1280);
        $alto = config('browsershot.alto', 800);
        $rutaTemp = storage_path('app/temp/captura-producto-' . $productoId . '-' . uniqid() . '.png');

        try {
            if (! is_dir(dirname($rutaTemp))) {
                @mkdir(dirname($rutaTemp), 0755, true);
            }

            $shot = Browsershot::url($urlPagina)
                ->noSandbox()
                ->addChromiumArguments([0 => 'disable-setuid-sandbox']) // Crucial para Linux (VPS/servidor sin display)
                ->timeout($timeout)
                ->windowSize($ancho, $alto);

            $this->aplicarEstiloOcultarElementos($shot);
            $this->aplicarCierrePopups($shot);

            $shot->save($rutaTemp);

            if (! is_file($rutaTemp) || filesize($rutaTemp) === 0) {
                return null;
            }

            $contenido = file_get_contents($rutaTemp);
            @unlink($rutaTemp);

            return $contenido !== false ? $contenido : null;
        } catch (ProcessTimedOutException $e) {
            Log::warning('NotificadorTelegram: Browsershot timeout al capturar producto', [
                'url' => $urlPagina,
                'producto_id' => $productoId,
                'timeout' => $timeout,
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
     * Envía la foto (captura) a Telegram con el caption de bajada histórica.
     */
    private function enviarFotoConCaptura(string $token, string $chatId, string $contenidoImagen, string $caption, ?string $urlAfiliado): void
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
        }
    }

    /**
     * Envía solo texto cuando no hay captura disponible (fallback).
     */
    private function enviarMensajeBajadaSoloTexto(string $token, string $chatId, string $caption, ?string $urlAfiliado): void
    {
        $urlApi = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = [
            'chat_id' => $chatId,
            'text' => $caption . "\n\n🖼️ (Captura no disponible)",
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
    }

    /**
     * Envía al canal Gratis un mensaje de bajada solo texto (sin captura ni sufijo "Captura no disponible").
     * Usado por enviarOfertaSegunCalidad para ofertas 10–29.9% y ahorrar recursos.
     */
    private function enviarMensajeBajadaSoloTextoCanalFree(string $token, string $chatId, string $caption, ?string $urlAfiliado): void
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
    }

    /**
     * Descarga la imagen desde la URL de la tienda a memoria (evita que Telegram haga la petición y reciba bloqueos).
     * Timeout alto (20-30s) para CDNs lentos como Coppel. Configurable en config/rastreador.php.
     */
    private function descargarImagen(string $url): ?string
    {
        $timeout = config('rastreador.timeout_imagen_telegram', 25);
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Referer' => parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/',
            ])
                ->timeout($timeout)
                ->connectTimeout(10)
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > 5 * 1024 * 1024) {
                return null;
            }

            return $body;
        } catch (\Throwable $e) {
            Log::debug('NotificadorTelegram: error descargando imagen', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Genera un nombre de archivo seguro para el adjunto (Telegram espera extensión para el tipo de contenido).
     */
    private function nombreArchivoImagen(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path !== null && $path !== '') {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                return 'foto.' . $ext;
            }
        }

        return 'foto.jpg';
    }

    /**
     * Envía solo texto (sin foto) con botón cuando el producto no tiene imagen_url.
     */
    private function enviarMensajeSinFoto(string $token, string $chatId, string $caption, ?string $urlAfiliado): void
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
