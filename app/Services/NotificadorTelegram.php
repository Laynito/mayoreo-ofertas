<?php

namespace App\Services;

use App\Exceptions\TelegramRateLimitException;
use App\Models\Configuracion;
use App\Models\NotificacionLog;
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
 * Notificador de ofertas vía Telegram. Un solo canal (TELEGRAM_CHAT_ID).
 * Prioridad: que la notificación nunca deje de salir.
 * Orden de imagen: 1) Browsershot (si Chrome está disponible), 2) API externa de capturas (opcional), 3) Imagen del producto (motor), 4) Solo texto.
 * Toggle enviar_imagenes: si está desactivado, se envía solo texto.
 */
class NotificadorTelegram
{
    public function __construct(
        private readonly AffiliateLinkService $affiliateLinkService,
        private readonly RedirectLinkService $redirectLinkService,
        private readonly MercadoLibreShortUrlService $mercadoLibreShortUrlService
    ) {}

    /**
     * URL para el botón "Ver en Tienda": mayoreo.cloud/r/{codigo}. Para Mercado Libre intenta meli.la (API short_urls); si falla, usa URL larga con micosmtics.
     */
    private function urlParaBotonVerEnTienda(Producto $producto, string $canal = 'Mayoreo_Cloud_Bot'): ?string
    {
        $urlDestino = null;
        if (! empty($producto->url_afiliado) && str_starts_with($producto->url_afiliado, 'http')) {
            $urlDestino = $producto->url_afiliado;
        } else {
            $base = $producto->url_original ?? $producto->affiliate_url;
            if ($base !== null && $base !== '' && str_starts_with($base, 'http')) {
                $urlDestino = $this->affiliateLinkService->enlaceParaTelegram($base, $producto->tienda_origen ?? '', $canal);
            }
        }

        if (trim($producto->tienda_origen ?? '') === 'Mercado Libre' && $urlDestino !== null && str_contains($urlDestino, 'mercadolibre.com')) {
            $short = $this->mercadoLibreShortUrlService->acortar($urlDestino);
            if ($short !== null && $short !== '') {
                $urlDestino = $short;
            }
        }

        if ($urlDestino === null || $urlDestino === '') {
            return null;
        }
        return $this->redirectLinkService->crear($urlDestino, $canal);
    }

    /**
     * Para productos de Mercado Libre: URL a mostrar (meli.la o larga) e Item ID para el mensaje. Fallback automático a URL larga si la API falla.
     *
     * @return array{url_display: string, item_id: string}|null
     */
    private function obtenerDatosEnlaceMercadoLibre(Producto $producto): ?array
    {
        if (trim($producto->tienda_origen ?? '') !== 'Mercado Libre') {
            return null;
        }
        $urlLarga = $producto->url_original ?? $producto->affiliate_url ?? '';
        if ($urlLarga === '' || ! str_starts_with($urlLarga, 'http')) {
            return null;
        }
        $short = $this->mercadoLibreShortUrlService->acortar($urlLarga);
        $urlDisplay = ($short !== null && $short !== '') ? $short : $urlLarga;
        $itemId = MercadoLibreShortUrlService::extraerItemId($urlLarga, $producto->sku_tienda) ?? '';

        return ['url_display' => $urlDisplay, 'item_id' => $itemId];
    }

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
     * Devuelve destinos: Chat Free siempre (si está configurado); Chat Premium si % ≥ umbral y está configurado.
     * Cada elemento es ['chat_id' => string, 'caption' => string].
     *
     * @return array<int, array{chat_id: string, caption: string}>
     */
    private function destinosParaOferta(Producto $producto, float $porcentaje, bool $soloTexto = false): array
    {
        $datosMl = $this->obtenerDatosEnlaceMercadoLibre($producto);
        $caption = $this->construirCaption($producto, $porcentaje, $soloTexto, $datosMl);
        $destinos = [];
        $chatFree = Configuracion::getTelegramChatId();
        if ($chatFree !== null && (string) $chatFree !== '') {
            $destinos[] = ['chat_id' => (string) $chatFree, 'caption' => $caption];
        }
        $umbralPremium = Configuracion::porcentajeMinimoParaPremium();
        $chatPremium = Configuracion::getTelegramChatIdPremium();
        if ($porcentaje >= $umbralPremium && $chatPremium !== null && (string) $chatPremium !== '' && (string) $chatPremium !== (string) $chatFree) {
            $destinos[] = ['chat_id' => (string) $chatPremium, 'caption' => $caption];
        }
        return $destinos;
    }

    /** Para bajada histórica: canal único (TELEGRAM_CHAT_ID) si bajada ≥ 10%. */
    private function chatIdParaBajadaHistorica(float $porcentajeBajada): ?string
    {
        if ($porcentajeBajada < 10.0) {
            return null;
        }
        $chat = Configuracion::getTelegramChatId();
        return $chat !== null && (string) $chat !== '' ? (string) $chat : null;
    }

    /** Horas durante las cuales no se reenvía la misma oferta (producto + precio) a Telegram. */
    private const HORAS_ANTES_REENVIAR_OFERTA = 12;

    /**
     * Notifica una oferta: captura de pantalla (Browsershot) de la página del producto al canal principal.
     * Si la captura falla, se envía solo texto. Evita duplicados en HORAS_ANTES_REENVIAR_OFERTA.
     */
    public function notificarOferta(Producto $producto): void
    {
        $porcentaje = $producto->porcentaje_ahorro !== null ? (float) $producto->porcentaje_ahorro : 0;
        $requiereAdicional = Configuracion::requiereDescuentoAdicional();

        if ($requiereAdicional && ! $producto->permite_descuento_adicional) {
            NotificacionLog::registrar($producto->id, $producto->tienda_origen, null, NotificacionLog::ESTADO_OMITIDO, 'Producto no permite descuento adicional', null);
            return;
        }

        $destinos = $this->destinosParaOferta($producto, $porcentaje);
        if ($destinos === []) {
            Log::warning('NotificadorTelegram: oferta NO enviada; ningún canal configurado. Revisa TELEGRAM_CHAT_ID en .env y ejecuta telegram:verificar.', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
            ]);
            NotificacionLog::registrar($producto->id, $producto->tienda_origen, null, NotificacionLog::ESTADO_OMITIDO, 'Ningún canal configurado (Chat ID)', null);
            return;
        }

        $claveDuplicado = 'telegram_oferta_enviada_' . $producto->id . '_' . (string) ($producto->precio_oferta ?? '');
        if (Cache::has($claveDuplicado)) {
            Log::debug('NotificadorTelegram: oferta omitida (ya enviada recientemente)', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
            ]);
            NotificacionLog::registrar($producto->id, $producto->tienda_origen, null, NotificacionLog::ESTADO_OMITIDO, 'Ya enviado recientemente (duplicado)', null);
            return;
        }

        $token = Configuracion::getTelegramToken();
        if (empty($token)) {
            Log::debug('NotificadorTelegram: TELEGRAM_BOT_TOKEN no configurado.');
            NotificacionLog::registrar($producto->id, $producto->tienda_origen, null, NotificacionLog::ESTADO_OMITIDO, 'Bot Token no configurado', null);
            return;
        }

        if (! Configuracion::enviarImagenes()) {
            $urlAfiliado = $this->urlParaBotonVerEnTienda($producto);
            $this->enviarOfertaSoloTextoADestinos($producto, $porcentaje, $destinos);
            foreach ($destinos as $destino) {
                NotificacionLog::registrar($producto->id, $producto->tienda_origen, $destino['chat_id'], NotificacionLog::ESTADO_ENVIADO, null, $urlAfiliado);
            }
            Cache::put($claveDuplicado, true, now()->addHours(self::HORAS_ANTES_REENVIAR_OFERTA));
            return;
        }

        Log::info('NotificadorTelegram: oferta enviada', [
            'producto_id' => $producto->id,
            'sku_tienda' => $producto->sku_tienda,
            'porcentaje_ahorro' => $porcentaje,
            'destinos' => count($destinos),
        ]);

        $urlAfiliado = $this->urlParaBotonVerEnTienda($producto);
        $this->enviarConCapturaOFallbackADestinos($token, $destinos, $producto, $urlAfiliado, 'NotificadorTelegram: producto sin URL para captura de oferta');

        foreach ($destinos as $destino) {
            NotificacionLog::registrar($producto->id, $producto->tienda_origen, $destino['chat_id'], NotificacionLog::ESTADO_ENVIADO, null, $urlAfiliado);
        }
        try {
            Cache::put($claveDuplicado, true, now()->addHours(self::HORAS_ANTES_REENVIAR_OFERTA));
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
        $token = Configuracion::getTelegramToken();
        if (empty($token)) {
            return;
        }
        $urlAfiliado = $this->urlParaBotonVerEnTienda($producto);
        foreach ($destinos as $destino) {
            $this->enviarMensajeSinFoto($token, $destino['chat_id'], $destino['caption'], $urlAfiliado, $producto->id);
        }
    }

    /**
     * Captura una vez y envía al destino. Misma captura y caption.
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

        // No usar Browsershot para URLs de tracking (click1, mclics): no son página de producto y llenan logs de error.
        $esUrlTracking = str_contains($urlPagina, 'click1.mercadolibre') || str_contains($urlPagina, 'mclics');
        if ($esUrlTracking) {
            Log::info('NotificadorTelegram: URL de tracking ML detectada, omitiendo Browsershot.', [
                'producto_id' => $producto->id,
                'tienda' => $producto->tienda_origen,
            ]);
        }

        $contenidoCaptura = null;
        if (! $esUrlTracking && $this->chromeDisponible()) {
            $contenidoCaptura = $this->capturarPantallaProductoConReintentos($urlPagina, $producto->id);
        } elseif (! $esUrlTracking) {
            Log::info('NotificadorTelegram: Chrome no disponible, omitiendo Browsershot.', [
                'producto_id' => $producto->id,
            ]);
        }

        if ($contenidoCaptura === null && ! $esUrlTracking) {
            $contenidoCaptura = $this->capturarConApiExterna($urlPagina, $producto->id);
        }

        if ($contenidoCaptura !== null && $contenidoCaptura !== '') {
            foreach ($destinos as $destino) {
                $this->enviarFotoConCaptura($token, $destino['chat_id'], $contenidoCaptura, $destino['caption'], $urlAfiliado, $producto->id);
            }
            return;
        }

        // Fallback: usar imagen del listado (imagen_url) extraída por el motor — prioridad: que la notificación siempre salga.
        // Las imágenes se descargan siempre por IP directa del VPS (sin proxy) para no consumir GB del proxy.
        $imagenUrl = $producto->imagen_url ?? '';
        if ($imagenUrl !== '' && str_starts_with($imagenUrl, 'http')) {
            $contenidoImagen = $this->descargarImagenListado($imagenUrl);
            if ($contenidoImagen !== null && $contenidoImagen !== '') {
                Log::info('NotificadorTelegram: usando imagen del producto (motor).', [
                    'producto_id' => $producto->id,
                    'tienda' => $producto->tienda_origen,
                ]);
                $sufijoListado = "\n\n🖼️ (Imagen del producto)";
                foreach ($destinos as $destino) {
                    $this->enviarFotoConCaptura($token, $destino['chat_id'], $contenidoImagen, $destino['caption'] . $sufijoListado, $urlAfiliado, $producto->id);
                }
                return;
            }
        }

        Log::info('NotificadorTelegram: envío solo texto (sin captura ni imagen del producto).', [
            'producto_id' => $producto->id,
            'url' => $urlPagina,
            'tienda' => $producto->tienda_origen,
        ]);
        foreach ($destinos as $destino) {
            $this->enviarMensajeSinFoto($token, $destino['chat_id'], $destino['caption'] . $captionFallback, $urlAfiliado, $producto->id);
        }
    }

    /**
     * Descarga la imagen del producto para Telegram. NUNCA usa proxy: solo IP directa del VPS.
     * El proxy se usa únicamente para el GET inicial del HTML; las imágenes no consumen GB del proxy.
     */
    private function descargarImagenListado(string $imagenUrl): ?string
    {
        try {
            $response = Http::timeout(15)->connectTimeout(5)->get($imagenUrl);
            if (! $response->successful()) {
                return null;
            }
            $body = $response->body();
            if ($body === '' || strlen($body) < 100) {
                return null;
            }
            $contentType = $response->header('Content-Type', '');
            if (! str_contains($contentType, 'image/')) {
                return null;
            }

            return $body;
        } catch (\Throwable $e) {
            Log::debug('NotificadorTelegram: no se pudo descargar imagen del listado', [
                'url' => $imagenUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
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
     * Envía la oferta solo como texto (sin foto). Usado cuando enviar_imagenes está desactivado o como fallback.
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
    }

    /**
     * Construye el texto del mensaje. Diseño unificado para todas las ofertas.
     * Para Mercado Libre se añade bloque: ID para buscador + enlace (meli.la o URL larga).
     *
     * @param  array{url_display: string, item_id: string}|null  $datosMl  Solo para tienda Mercado Libre.
     */
    private function construirCaption(Producto $producto, float $porcentaje, bool $soloTexto, ?array $datosMl = null): string
    {
        $precioOriginal = number_format((float) $producto->precio_original, 2);
        $precioOferta = $producto->precio_oferta !== null
            ? number_format((float) $producto->precio_oferta, 2)
            : $precioOriginal;
        $nombreLimpio = $this->escaparHtml(strip_tags((string) ($producto->nombre ?? '')));
        $tienda = $this->escaparHtml($producto->tienda_origen ?? '');
        $ahorro = number_format($porcentaje, 1);
        $sep = $soloTexto ? "\n──────────────\n" : "\n";
        $lineas = [
            '🛒 <b>Nueva oferta</b>',
            $sep,
            '<b>' . $nombreLimpio . '</b>',
            '',
            '<b>Precio oferta: $' . $precioOferta . '</b>',
            'Precio original: <s>$' . $precioOriginal . '</s>',
            'Ahorro: <b>' . $ahorro . '%</b>',
            $soloTexto ? "\n──────────────" : '',
            $tienda,
        ];
        $caption = implode("\n", array_filter($lineas));

        if ($datosMl !== null && (trim($producto->tienda_origen ?? '') === 'Mercado Libre')) {
            $caption .= "\n\n🔍 Pega este ID en el buscador de Mercado Libre: " . $this->escaparHtml($datosMl['item_id'] ?: '—');
            $caption .= "\n🔗 O ingresa al siguiente link:\n" . $datosMl['url_display'];
        }

        return $caption;
    }

    private function escaparHtml(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Envía la oferta según la calidad de la bajada al canal principal.
     * Regla: si permite_descuento_adicional es false, no se envía.
     * - Bajada ≥30%: mensaje con captura Browsershot (formato "BAJADA HISTÓRICA").
     * - Bajada entre 10% y 29.9%: mensaje solo texto (sin captura).
     * - Bajada &lt;10%: no se envía.
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

        $token = Configuracion::getTelegramToken();
        if (empty($token)) {
            Log::debug('NotificadorTelegram: TELEGRAM_BOT_TOKEN no configurado.');
            return;
        }

        $idCanal = Configuracion::getTelegramChatId();
        if ($idCanal === null || (string) $idCanal === '') {
            Log::debug('NotificadorTelegram: TELEGRAM_CHAT_ID no configurado para bajada histórica.');
            return;
        }

        if ($bajada >= 30) {
            $this->notificarBajadaHistoricaConCaptura($producto, $precioAyer, $precioHoy, (string) $idCanal);
        } else {
            $caption = $this->construirCaptionBajadaHistorica($producto, $precioAyer, $precioHoy, $bajada);
            $urlAfiliado = $this->urlParaBotonVerEnTienda($producto);
            $this->enviarMensajeSinFoto($token, (string) $idCanal, $caption, $urlAfiliado, $producto->id);
        }
        Cache::put($claveDuplicado, true, now()->addHours(self::HORAS_ANTES_REENVIAR_OFERTA));
        Log::info('NotificadorTelegram: oferta de bajada enviada', [
            'producto_id' => $producto->id,
            'sku_tienda' => $producto->sku_tienda,
            'bajada_porcentaje' => round($bajada, 1),
        ]);
    }

    /**
     * Distribuye una oferta de bajada de precio al canal principal.
     * Reglas: no enviar si el producto no permite descuento adicional; &lt;10% no se notifica.
     */
    public function distribuirOferta(Producto $producto, float $precioAyer, float $precioHoy): void
    {
        if (! $producto->permite_descuento_adicional) {
            return;
        }

        $porcentajeBajada = $precioAyer > 0
            ? (($precioAyer - $precioHoy) / $precioAyer) * 100
            : 0.0;

        if ($porcentajeBajada < 10) {
            return;
        }

        $idCanal = Configuracion::getTelegramChatId();
        if ($idCanal === null || (string) $idCanal === '') {
            Log::debug('NotificadorTelegram: TELEGRAM_CHAT_ID no configurado para distribuirOferta.');
            return;
        }

        $this->notificarBajadaHistoricaConCaptura($producto, $precioAyer, $precioHoy, (string) $idCanal);
    }

    /**
     * Notifica una bajada histórica de precio con captura de pantalla de la página del producto.
     * Si se pasa $chatId se usa ese canal; si no, se usa TELEGRAM_CHAT_ID (bajada ≥ 10%).
     */
    public function notificarBajadaHistoricaConCaptura(Producto $producto, float $precioAyer, float $precioHoy, ?string $chatId = null): void
    {
        $token = Configuracion::getTelegramToken();
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
        $urlAfiliado = $this->urlParaBotonVerEnTienda($producto);
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
        $esCalimax = str_contains($urlPagina, 'calimax.com.mx');
        $esMercadoLibre = str_contains($urlPagina, 'mercadolibre.com');
        if ($esCalimax) {
            $timeout = max($timeout, 45);
            Log::info('NotificadorTelegram: intentando captura Browsershot para Calimax', [
                'producto_id' => $productoId,
                'url' => $urlPagina,
            ]);
        }
        if ($esMercadoLibre) {
            $timeout = max($timeout, 30);
        }
        $rutaTemp = storage_path('app/temp/captura-producto-' . $productoId . '-' . (getmypid() ?: 0) . '-' . uniqid('', true) . '.png');

        try {
            if (! is_dir(dirname($rutaTemp))) {
                @mkdir(dirname($rutaTemp), 0755, true);
            }

            $shot = Browsershot::url($urlPagina)
                ->noSandbox()
                ->addChromiumArguments(['--disable-setuid-sandbox'])
                ->timeout($timeout)
                ->windowSize(1280, 800);

            $chromePath = $this->resolverRutaChrome();
            $shot->setChromePath($chromePath !== '' ? $chromePath : '/usr/bin/google-chrome');

            // Calimax/VTEX y Mercado Libre: User-Agent de navegador real para reducir bloqueos a headless.
            if ($esCalimax || $esMercadoLibre) {
                $shot->userAgent(
                    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
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
            if ($esMercadoLibre) {
                Log::info('NotificadorTelegram: captura Browsershot Mercado Libre OK', ['producto_id' => $productoId]);
            }

            return $contenido !== false ? $contenido : null;
        } catch (ProcessTimedOutException $e) {
            Log::warning('NotificadorTelegram: Browsershot timeout al capturar producto', [
                'url' => $urlPagina,
                'producto_id' => $productoId,
                'timeout' => $timeout,
                'es_calimax' => $esCalimax,
                'es_mercadolibre' => $esMercadoLibre ?? false,
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
     * Resuelve la ruta ejecutable de Chrome/Chromium para Browsershot (Linux: Could not find Chrome).
     * Usa config browsershot.chrome_path; si no existe o no es ejecutable, prueba rutas habituales.
     */
    private function resolverRutaChrome(): string
    {
        $configPath = trim((string) config('browsershot.chrome_path', ''));
        if ($configPath !== '' && is_executable($configPath)) {
            return $configPath;
        }
        $candidatos = ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome'];
        foreach ($candidatos as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * Comprueba si Chrome/Chromium está disponible antes de intentar Browsershot.
     * Evita timeouts y errores cuando el binario no está instalado o no es ejecutable.
     */
    private function chromeDisponible(): bool
    {
        $ruta = $this->resolverRutaChrome();
        if ($ruta !== '') {
            return true;
        }
        $fallback = '/usr/bin/google-chrome';
        return is_executable($fallback);
    }

    /**
     * Intenta obtener una captura de pantalla vía API externa (ej. ScreenshotLayer).
     * Solo se usa como fallback cuando Browsershot no está disponible o falla.
     * Config: services.captura_api.url y services.captura_api.key (opcional según proveedor).
     *
     * @return string|null Contenido binario de la imagen o null si falla o no está configurado.
     */
    private function capturarConApiExterna(string $urlPagina, int $productoId): ?string
    {
        $apiUrl = config('services.captura_api.url');
        $apiKey = config('services.captura_api.key');
        if ($apiUrl === null || $apiUrl === '') {
            return null;
        }

        $params = ['url' => $urlPagina];
        if ($apiKey !== null && $apiKey !== '') {
            $params['access_key'] = $apiKey;
        }
        $requestUrl = str_contains($apiUrl, '?') ? $apiUrl . '&' . http_build_query($params) : $apiUrl . '?' . http_build_query($params);

        try {
            $response = Http::timeout(25)->connectTimeout(10)->get($requestUrl);
            if (! $response->successful()) {
                Log::debug('NotificadorTelegram: API captura externa falló', [
                    'producto_id' => $productoId,
                    'status' => $response->status(),
                ]);
                return null;
            }
            $body = $response->body();
            if ($body === '' || strlen($body) < 200) {
                return null;
            }
            $contentType = $response->header('Content-Type', '');
            if (! str_contains($contentType, 'image/') && ! str_contains($contentType, 'octet-stream')) {
                Log::debug('NotificadorTelegram: API captura no devolvió imagen', [
                    'producto_id' => $productoId,
                    'content_type' => $contentType,
                ]);
                return null;
            }
            Log::info('NotificadorTelegram: captura vía API externa OK', ['producto_id' => $productoId]);
            return $body;
        } catch (\Throwable $e) {
            Log::debug('NotificadorTelegram: excepción en API captura externa', [
                'producto_id' => $productoId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Envía la foto (captura) a Telegram. Si se pasa $productoId, guarda el message_id para limpieza posterior.
     */
    private function enviarFotoConCaptura(string $token, string $chatId, string $contenidoImagen, string $caption, ?string $urlAfiliado, ?int $productoId = null): void
    {
        Log::info('Enviando mensaje al Chat ID: ' . $chatId);
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
        Log::info('Enviando mensaje al Chat ID: ' . $chatId);
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
        $chatId = Configuracion::getTelegramChatId();
        $this->enviarMensajeAChat($chatId !== null && $chatId !== '' ? (string) $chatId : null, $texto);
    }

    /**
     * Envía una alerta de error al canal configurado (fallos de envío de ofertas).
     * Usa HTML para formato. Si el chat no está configurado, no hace nada.
     */
    public function enviarMensajeAlertaError(string $textoHtml): void
    {
        $chatId = Configuracion::getTelegramChatId();
        if ($chatId === null || (string) $chatId === '') {
            return;
        }
        $this->enviarMensajeAChat((string) $chatId, $textoHtml, 'HTML');
    }

    /**
     * Envía un mensaje de prueba al chat indicado (Centro de Control → Probar Conexión).
     *
     * @return array{ok: bool, error?: string}
     */
    public function enviarMensajePruebaAChat(string $chatId): array
    {
        $token = Configuracion::getTelegramToken();
        if (empty($token)) {
            return ['ok' => false, 'error' => 'Bot Token no configurado.'];
        }
        if ($chatId === '') {
            return ['ok' => false, 'error' => 'Chat ID vacío.'];
        }
        $urlApi = "https://api.telegram.org/bot{$token}/sendMessage";
        $response = Http::withOptions(['verify' => false])
            ->timeout(10)
            ->connectTimeout(5)
            ->asForm()
            ->post($urlApi, [
                'chat_id' => $chatId,
                'text' => '🧪 Prueba desde Centro de Control. Si ves esto, el bot y el canal están bien.',
                'parse_mode' => 'HTML',
            ]);
        if ($response->successful()) {
            return ['ok' => true];
        }
        $body = $response->json();
        $desc = $body['description'] ?? $response->body();

        return ['ok' => false, 'error' => $desc];
    }

    /**
     * Envía un mensaje de texto a un chat_id concreto (para resúmenes por canal).
     *
     * @param  string|null  $parseMode  'HTML' para formato bold, etc.; null para texto plano.
     */
    private function enviarMensajeAChat(?string $chatId, string $texto, ?string $parseMode = null): void
    {
        $token = Configuracion::getTelegramToken();
        if (empty($token) || $chatId === null || $chatId === '') {
            return;
        }
        Log::info('Enviando mensaje al Chat ID: ' . $chatId);
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
     * Envía al canal principal un resumen corto al finalizar el rastreo.
     */
    public function enviarResumenFinalRastreo(string $tiendaOrigen, int $productosProcesados, int $ofertasEnviadas): void
    {
        $chatId = Configuracion::getTelegramChatId();
        if ($chatId === null || (string) $chatId === '') {
            return;
        }
        $texto = "🏁 <b>Rastreo de {$tiendaOrigen} finalizado.</b>\n\n"
            . "{$productosProcesados} productos procesados, {$ofertasEnviadas} ofertas enviadas.";
        $this->enviarMensajeAChat((string) $chatId, $texto, 'HTML');
    }

    /**
     * Envía al canal principal un resumen de cuántas ofertas recibirán.
     *
     * @param  Collection<int, Producto>  $productos
     */
    public function enviarResumenOfertasPorCanal(Collection $productos, string $tiendaOrigen): void
    {
        if ($productos->isEmpty()) {
            return;
        }
        $total = $productos->count();
        $chatId = Configuracion::getTelegramChatId();
        if ($chatId === null || (string) $chatId === '') {
            return;
        }
        $this->enviarMensajeAChat((string) $chatId, "🛒 <b>{$tiendaOrigen}: Resumen</b>\n\nVas a recibir <b>{$total} ofertas</b>.", 'HTML');
    }
}
