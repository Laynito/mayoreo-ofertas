<?php

namespace App\Services;

use App\Exceptions\TelegramRateLimitException;
use App\Models\Configuracion;
use App\Models\NotificacionLog;
use App\Models\Producto;
use App\Services\MercadoLibreTokenService;
use App\Services\MercadoLibreShortUrlService;
use App\Support\HttpRastreador;
use App\Models\TelegramMensajeOferta;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Notificador de ofertas vía Telegram. Un solo canal (TELEGRAM_CHAT_ID).
 * Prioridad: que la notificación nunca deje de salir.
 *
 * Decisión única de imagen: debeEnviarConImagen() (Ajustes → Enviar imágenes).
 * Si está desactivado, todos los flujos (ofertas nuevas y bajada histórica) envían solo texto.
 *
 * Orden de imagen cuando está activado: 1) Browsershot (si aplica), 2) API externa (opcional),
 * 3) Imagen del producto (motor), 4) API ML (Items/Products), 5) Solo texto.
 */
class NotificadorTelegram
{
    public function __construct(
        private readonly AffiliateLinkService $affiliateLinkService,
        private readonly RedirectLinkService $redirectLinkService,
        private readonly MercadoLibreShortUrlService $mercadoLibreShortUrlService,
        private readonly NormalizadorEnlacesAfiliadoService $normalizadorEnlacesAfiliado
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
        $canonica = $this->normalizadorEnlacesAfiliado->urlMercadoLibreCanonicaCorta($urlLarga);
        if ($canonica !== null) {
            $urlLarga = $canonica;
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
     * Punto único de decisión: si las notificaciones deben llevar imagen/captura.
     * Cuando es false, todos los flujos (ofertas nuevas y bajada histórica) envían solo texto.
     */
    private function debeEnviarConImagen(): bool
    {
        return Configuracion::enviarImagenes();
    }

    /**
     * Notifica una oferta: captura de pantalla (Browsershot) de la página del producto al canal principal.
     * Si la captura falla, se envía solo texto. Evita duplicados en HORAS_ANTES_REENVIAR_OFERTA.
     */
    public function notificarOferta(Producto $producto): void
    {
        $porcentaje = $producto->porcentaje_ahorro !== null ? (float) $producto->porcentaje_ahorro : 0;
        $requiereAdicional = Configuracion::requiereDescuentoAdicional();

        if ($requiereAdicional && ! $producto->permite_descuento_adicional) {
            NotificacionLog::registrar($producto->id, $producto->tienda_origen, null, NotificacionLog::ESTADO_OMITIDO, 'Producto no permite descuento adicional', null, $producto->origen_rastreo ?? null);
            return;
        }

        $destinos = $this->destinosParaOferta($producto, $porcentaje);
        if ($destinos === []) {
            Log::warning('NotificadorTelegram: oferta NO enviada; ningún canal configurado. Revisa TELEGRAM_CHAT_ID en .env y ejecuta telegram:verificar.', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
            ]);
            NotificacionLog::registrar($producto->id, $producto->tienda_origen, null, NotificacionLog::ESTADO_OMITIDO, 'Ningún canal configurado (Chat ID)', null, $producto->origen_rastreo ?? null);
            return;
        }

        $claveDuplicado = 'telegram_oferta_enviada_' . $producto->id . '_' . (string) ($producto->precio_oferta ?? '');
        if (Cache::has($claveDuplicado)) {
            Log::debug('NotificadorTelegram: oferta omitida (ya enviada recientemente)', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
            ]);
            NotificacionLog::registrar($producto->id, $producto->tienda_origen, null, NotificacionLog::ESTADO_OMITIDO, 'Ya enviado recientemente (duplicado)', null, $producto->origen_rastreo ?? null);
            return;
        }

        $token = Configuracion::getTelegramToken();
        if (empty($token)) {
            Log::debug('NotificadorTelegram: TELEGRAM_BOT_TOKEN no configurado.');
            NotificacionLog::registrar($producto->id, $producto->tienda_origen, null, NotificacionLog::ESTADO_OMITIDO, 'Bot Token no configurado', null, $producto->origen_rastreo ?? null);
            return;
        }

        if (! $this->debeEnviarConImagen()) {
            Log::info('NotificadorTelegram: enviar_imagenes desactivado en Ajustes; envío solo texto (sin capturas).', ['producto_id' => $producto->id]);
            $urlAfiliado = $this->urlParaBotonVerEnTienda($producto);
            $this->enviarOfertaSoloTextoADestinos($producto, $porcentaje, $destinos);
            foreach ($destinos as $destino) {
                NotificacionLog::registrar($producto->id, $producto->tienda_origen, $destino['chat_id'], NotificacionLog::ESTADO_ENVIADO, null, $urlAfiliado, $producto->origen_rastreo ?? null);
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
            NotificacionLog::registrar($producto->id, $producto->tienda_origen, $destino['chat_id'], NotificacionLog::ESTADO_ENVIADO, null, $urlAfiliado, $producto->origen_rastreo ?? null);
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
    /**
     * Detecta si el producto es de Mercado Libre (por tienda o por URL). Para ML no usamos captura web (evita pantalla de login).
     */
    private function esProductoMercadoLibre(Producto $producto, ?string $urlPagina = null): bool
    {
        if (trim($producto->tienda_origen ?? '') === 'Mercado Libre') {
            return true;
        }
        $url = $urlPagina ?? $producto->url_original ?? '';
        return $url !== '' && str_contains($url, 'mercadolibre.com');
    }

    private function enviarConCapturaOFallbackADestinos(string $token, array $destinos, Producto $producto, ?string $urlAfiliado, string $mensajeLogSinUrl): void
    {
        $urlPagina = $producto->url_original ?? $urlAfiliado;
        $esMercadoLibre = $this->esProductoMercadoLibre($producto, $urlPagina);

        // ——— Mercado Libre: no enviar ofertas sin enlace (evita TypeError y mensajes inútiles sin botón "Ver en Tienda").
        if ($esMercadoLibre) {
            $urlPaginaStr = (string) ($urlPagina ?? '');
            $tieneEnlace = trim($urlPaginaStr) !== '' || trim($urlAfiliado ?? '') !== '';
            if (! $tieneEnlace) {
                Log::warning('NotificadorTelegram: producto ML sin enlace — no se envía oferta', [
                    'producto_id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'sku_tienda' => $producto->sku_tienda,
                ]);
                return;
            }
            $this->enviarOfertaMercadoLibreSoloImagenApi($token, $destinos, $producto, $urlAfiliado, $urlPaginaStr);
            return;
        }

        $captionFallback = "\n\n🖼️ (Captura no disponible)";

        if (empty($urlPagina) || ! str_starts_with($urlPagina, 'http')) {
            Log::warning($mensajeLogSinUrl, ['producto_id' => $producto->id, 'tienda' => $producto->tienda_origen]);
            foreach ($destinos as $destino) {
                $this->enviarMensajeSinFoto($token, $destino['chat_id'], $destino['caption'] . $captionFallback, $urlAfiliado, $producto->id);
            }
            return;
        }

        $esUrlTracking = str_contains($urlPagina, 'click1.mercadolibre') || str_contains($urlPagina, 'mclics');
        $omitirBrowsershot = $esUrlTracking;

        $contenidoCaptura = null;
        if (! $omitirBrowsershot && $this->chromeDisponible()) {
            $contenidoCaptura = $this->capturarPantallaProductoConReintentos($urlPagina, $producto->id);
        } elseif (! $omitirBrowsershot) {
            Log::info('NotificadorTelegram: Chrome no disponible, omitiendo Browsershot.', [
                'producto_id' => $producto->id,
            ]);
        }

        if ($contenidoCaptura === null && ! $omitirBrowsershot) {
            $contenidoCaptura = $this->capturarConApiExterna($urlPagina, $producto->id);
        }

        if ($contenidoCaptura !== null && $contenidoCaptura !== '') {
            $this->guardarCapturaEnProducto($contenidoCaptura, $producto->id);
            foreach ($destinos as $destino) {
                $this->enviarFotoConCaptura($token, $destino['chat_id'], $contenidoCaptura, $destino['caption'], $urlAfiliado, $producto->id);
            }
            return;
        }

        // Otras tiendas: fallback a imagen del motor si existe.
        $imagenUrl = $producto->imagen_url ?? '';
        if ($imagenUrl !== '' && str_starts_with($imagenUrl, 'http')) {
            $contenidoImagen = $this->descargarImagenListado($imagenUrl);
            if ($contenidoImagen !== null && $contenidoImagen !== '') {
                Log::info('NotificadorTelegram: usando imagen del producto (motor).', [
                    'producto_id' => $producto->id,
                    'tienda' => $producto->tienda_origen,
                ]);
                $this->guardarCapturaEnProducto($contenidoImagen, $producto->id);
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

    /** URL de imagen de relleno para probar envío ML cuando no hay imagen del producto (logo ML). */
    private const ML_IMAGEN_PRUEBA = 'https://http2.mlstatic.com/frontend-assets/ml-extras/navigation/logo-desktop-v2.png';

    /**
     * Flujo Mercado Libre: siempre sendPhoto. Si no hay imagen del producto, se usa imagen de prueba para diagnosticar.
     *
     * @param  array<int, array{chat_id: string, caption: string}>  $destinos
     */
    private function enviarOfertaMercadoLibreSoloImagenApi(string $token, array $destinos, Producto $producto, ?string $urlAfiliado, string $urlPagina): void
    {
        $sufijoListado = "\n\n🖼️ (Imagen del producto)";
        $sufijoImagenPrueba = "\n\n🖼️ (Imagen de prueba: producto sin URL de imagen)";
        $sinEnlace = trim($urlAfiliado ?? '') === '' && trim($producto->url_original ?? '') === '';
        $sufijoSinEnlace = $sinEnlace ? "\n\n⚠️ Enlace no disponible para este producto." : '';

        Log::info('NotificadorTelegram: Mercado Libre — forzando sendPhoto (sin envío solo texto).', [
            'producto_id' => $producto->id,
            'sin_enlace' => $sinEnlace,
        ]);

        // ML: enviar siempre por multipart (descargar + attach). Telegram no puede descargar desde mlstatic.com (imagen rota); nosotros sí.
        $imagenUrl = $producto->imagen_url ?? '';
        if ($imagenUrl !== '' && str_starts_with($imagenUrl, 'http')) {
            $contenido = $this->descargarImagenListado($imagenUrl);
            if ($contenido !== null && $contenido !== '') {
                Log::info('NotificadorTelegram: ML — sendPhoto multipart (motor/API rastreo).', ['producto_id' => $producto->id]);
                $this->guardarCapturaEnProducto($contenido, $producto->id);
                foreach ($destinos as $destino) {
                    $this->enviarFotoConCaptura($token, $destino['chat_id'], $contenido, $destino['caption'] . $sufijoListado . $sufijoSinEnlace, $urlAfiliado, $producto->id);
                }
                return;
            }
        }

        $imagenUrlApi = MercadoLibreImagenApiService::getImagenUrl($producto);
        if ($imagenUrlApi !== null && $imagenUrlApi !== '') {
            $contenido = $this->descargarImagenListado($imagenUrlApi);
            if ($contenido !== null && $contenido !== '') {
                Log::info('NotificadorTelegram: ML — sendPhoto multipart (API Items/Products).', ['producto_id' => $producto->id]);
                $this->guardarCapturaEnProducto($contenido, $producto->id);
                $this->actualizarImagenUrlSiVacia($producto, $imagenUrlApi);
                foreach ($destinos as $destino) {
                    $this->enviarFotoConCaptura($token, $destino['chat_id'], $contenido, $destino['caption'] . $sufijoListado . $sufijoSinEnlace, $urlAfiliado, $producto->id);
                }
                return;
            }
        }

        $imagenUrlOg = $this->obtenerImagenUrlOpenGraphMercadoLibreVariosUserAgents($urlPagina);
        if ($imagenUrlOg !== null && $imagenUrlOg !== '') {
            $contenido = $this->descargarImagenListado($imagenUrlOg);
            if ($contenido !== null && $contenido !== '') {
                Log::info('NotificadorTelegram: ML — sendPhoto multipart (og:image).', ['producto_id' => $producto->id]);
                $this->guardarCapturaEnProducto($contenido, $producto->id);
                foreach ($destinos as $destino) {
                    $this->enviarFotoConCaptura($token, $destino['chat_id'], $contenido, $destino['caption'] . $sufijoListado . $sufijoSinEnlace, $urlAfiliado, $producto->id);
                }
                return;
            }
        }

        // Sin imagen del producto: descargar logo ML y enviar por multipart (así se ve; por URL Telegram no la carga).
        $contenidoPrueba = $this->descargarImagenListado(self::ML_IMAGEN_PRUEBA);
        if ($contenidoPrueba !== null && $contenidoPrueba !== '') {
            Log::warning('NotificadorTelegram: ML sin imagen producto; enviando logo por multipart.', ['producto_id' => $producto->id]);
            foreach ($destinos as $destino) {
                $this->enviarFotoConCaptura($token, $destino['chat_id'], $contenidoPrueba, $destino['caption'] . $sufijoImagenPrueba . $sufijoSinEnlace, $urlAfiliado, $producto->id);
            }
            return;
        }
        foreach ($destinos as $destino) {
            $this->enviarFotoConUrl($token, $destino['chat_id'], self::ML_IMAGEN_PRUEBA, $destino['caption'] . $sufijoImagenPrueba . $sufijoSinEnlace, $urlAfiliado, $producto->id);
        }
    }

    /**
     * Persiste imagen_url en el producto cuando se obtuvo desde la API (evita llamar API en cada notificación).
     */
    private function actualizarImagenUrlSiVacia(Producto $producto, string $imagenUrl): void
    {
        $actual = $producto->imagen_url ?? '';
        if ($actual === '' && $imagenUrl !== '') {
            $producto->imagen_url = $imagenUrl;
            $producto->save();
            Log::info('NotificadorTelegram: producto ML actualizado con imagen_url desde API', ['producto_id' => $producto->id]);
        }
    }

    /**
     * Obtiene la URL de la imagen del producto como hace WhatsApp: pide la página con User-Agent
     * de "link preview" (WhatsApp/Facebook) y extrae og:image del HTML. ML suele devolver la ficha
     * con meta tags en lugar de redirigir a login para estos bots.
     *
     * @return string|null URL de og:image o null si falla o la imagen no parece de producto (ej. logo ML).
     */
    /**
     * Intenta obtener og:image con un User-Agent dado. Prueba WhatsApp y Telegram por si ML devuelve ficha solo a uno.
     */
    private function obtenerImagenUrlOpenGraphMercadoLibre(string $urlProducto, string $userAgent = 'WhatsApp/2.23.20.0 A'): ?string
    {
        if ($urlProducto === '' || ! str_contains($urlProducto, 'mercadolibre')) {
            return null;
        }
        try {
            $headers = [
                'User-Agent' => $userAgent,
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'es-MX,es;q=0.9',
            ];
            $request = Http::withHeaders($headers)->timeout(12)->connectTimeout(6);
            $request = HttpRastreador::conProxySiTexto($request, $urlProducto);
            $response = $request->get($urlProducto);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
            if ($html === '' || strlen($html) < 500) {
                return null;
            }

            if (preg_match('/<meta[^>]+property\s*=\s*["\']og:image["\'][^>]+content\s*=\s*["\']([^"\']+)["\']/i', $html, $m) ||
                preg_match('/<meta[^>]+content\s*=\s*["\']([^"\']+)["\'][^>]+property\s*=\s*["\']og:image["\']/i', $html, $m)) {
                $url = trim($m[1]);
                if ($url !== '' && str_starts_with($url, 'http') && str_contains($url, 'mlstatic.com')) {
                    return $url;
                }
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Prueba og:image con varios User-Agents (WhatsApp, Telegram, Googlebot) por si ML solo entrega ficha a alguno. */
    private function obtenerImagenUrlOpenGraphMercadoLibreVariosUserAgents(string $urlProducto): ?string
    {
        $userAgents = [
            'WhatsApp/2.23.20.0 A',
            'TelegramBot (like TwitterBot)',
            'Googlebot/2.1 (+http://www.google.com/bot.html)',
        ];
        foreach ($userAgents as $ua) {
            $url = $this->obtenerImagenUrlOpenGraphMercadoLibre($urlProducto, $ua);
            if ($url !== null) {
                return $url;
            }
        }
        return null;
    }

    /**
     * Descarga la imagen del producto (para multipart o para guardar en producto).
     * Para ML, Walmart y Sams: usa proxy (conProxy) y Referer de la tienda para evitar 403 del CDN.
     * verify => false evita fallos por certificados SSL del proxy.
     * Verifica que el cuerpo sea imagen (JPEG/PNG/GIF); si es HTML, registra error y devuelve null.
     */
    private function descargarImagenListado(string $imagenUrl): ?string
    {
        try {
            $esMl = str_contains($imagenUrl, 'mlstatic.com') || str_contains($imagenUrl, 'mercadolibre');
            $esWalmart = str_contains($imagenUrl, 'walmart.com') || str_contains($imagenUrl, 'walmartimages');
            $esSams = str_contains($imagenUrl, 'sams.com.mx') || str_contains($imagenUrl, 'samsclub');
            $headers = [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            ];
            if ($esMl) {
                $headers['Referer'] = 'https://www.mercadolibre.com.mx/';
            } elseif ($esWalmart) {
                $headers['Referer'] = 'https://www.walmart.com.mx/';
            } elseif ($esSams) {
                $headers['Referer'] = 'https://www.sams.com.mx/';
            }

            $usarProxy = $esMl || $esWalmart || $esSams;
            $request = Http::withOptions(['verify' => false])
                ->withHeaders($headers)
                ->timeout(15)
                ->connectTimeout(5);

            if ($usarProxy) {
                // conProxy: evita 403 de CDNs (ML, Walmart, Sams) cuando la descarga se hace desde el servidor.
                $request = HttpRastreador::conProxy($request);
            }

            $response = $request->get($imagenUrl);

            if (! $response->successful()) {
                Log::debug('NotificadorTelegram: descarga imagen no exitosa', ['url' => $imagenUrl, 'status' => $response->status()]);
                return null;
            }

            $body = $response->body();
            if ($body === '' || strlen($body) < 100) {
                return null;
            }

            if ($this->esHtmlEnLugarDeImagen($body)) {
                Log::error('ML Descarga fallida: Se recibió HTML en lugar de imagen', ['url' => $imagenUrl]);
                return null;
            }

            if (! $this->esCuerpoImagenValida($body)) {
                Log::debug('NotificadorTelegram: respuesta no es imagen válida (magic bytes)', ['url' => $imagenUrl]);
                return null;
            }

            $contentType = $response->header('Content-Type', '');
            if ($contentType !== '' && ! str_contains($contentType, 'image/')) {
                Log::debug('NotificadorTelegram: respuesta no es imagen', ['url' => $imagenUrl, 'content-type' => $contentType]);
                return null;
            }

            return $body;
        } catch (\Throwable $e) {
            Log::warning('NotificadorTelegram: no se pudo descargar imagen del listado', [
                'url' => $imagenUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function esHtmlEnLugarDeImagen(string $body): bool
    {
        $inicio = ltrim($body);
        return str_starts_with($inicio, '<!') || str_starts_with($inicio, '<html');
    }

    private function esCuerpoImagenValida(string $body): bool
    {
        if (strlen($body) < 4) {
            return false;
        }
        return str_starts_with($body, "\xFF\xD8\xFF")
            || str_starts_with($body, "\x89PNG")
            || str_starts_with($body, "GIF");
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

        $caption = $this->construirCaptionBajadaHistorica($producto, $precioAyer, $precioHoy, $bajada);
        $urlAfiliado = $this->urlParaBotonVerEnTienda($producto);
        if ($bajada >= 30 && $this->debeEnviarConImagen()) {
            $this->notificarBajadaHistoricaConCaptura($producto, $precioAyer, $precioHoy, (string) $idCanal);
        } else {
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

        if ($this->debeEnviarConImagen()) {
            $this->notificarBajadaHistoricaConCaptura($producto, $precioAyer, $precioHoy, (string) $idCanal);
        } else {
            $token = Configuracion::getTelegramToken();
            if (! empty($token)) {
                $caption = $this->construirCaptionBajadaHistorica(
                    $producto,
                    $precioAyer,
                    $precioHoy,
                    $porcentajeBajada
                );
                $urlAfiliado = $this->urlParaBotonVerEnTienda($producto);
                $this->enviarMensajeSinFoto($token, (string) $idCanal, $caption, $urlAfiliado, $producto->id);
            }
        }
    }

    /**
     * Notifica una bajada histórica de precio. Respeta enviar_imagenes: si está desactivado, envía solo texto.
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

        if (! $this->debeEnviarConImagen()) {
            $this->enviarMensajeSinFoto($token, $chatId, $caption, $urlAfiliado, $producto->id);
            return;
        }

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
        // Mercado Libre redirige a login con headless; no intentar captura web (se usa solo imagen API/motor).
        if (str_contains($urlPagina, 'mercadolibre.com')) {
            Log::debug('NotificadorTelegram: Browsershot omitido para URL ML (modo API).', ['producto_id' => $productoId]);
            return null;
        }

        $timeout = config('browsershot.timeout', 45);
        $esCalimax = str_contains($urlPagina, 'calimax.com.mx');
        $esMercadoLibre = false;
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
     * Guarda la captura de pantalla en storage y actualiza producto.captura_url para mostrarla en el sitio web.
     */
    private function guardarCapturaEnProducto(string $contenidoCaptura, int $productoId): void
    {
        try {
            $path = 'ofertas/producto_' . $productoId . '.png';
            Storage::disk('public')->put($path, $contenidoCaptura);
            $url = asset('storage/' . $path);
            Producto::where('id', $productoId)->update(['captura_url' => $url]);
        } catch (\Throwable $e) {
            Log::debug('NotificadorTelegram: no se pudo guardar captura en producto', [
                'producto_id' => $productoId,
                'error' => $e->getMessage(),
            ]);
        }
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
     * Envía la foto a Telegram: primero sendPhoto(photo=URL). Si Telegram no puede descargar la URL (ej. ML bloquea a Telegram),
     * fallback: descargamos nosotros la imagen y la enviamos por multipart (sendPhoto con attach).
     */
    private function enviarFotoConUrl(string $token, string $chatId, string $imagenUrl, string $caption, ?string $urlAfiliado, ?int $productoId = null): void
    {
        Log::info('DEBUG IMAGEN:', ['url' => $imagenUrl, 'metodo' => 'sendPhoto']);
        Log::emergency('DEBUG_FOTO_ML: ' . $imagenUrl);

        $urlApi = "https://api.telegram.org/bot{$token}/sendPhoto";
        $payload = [
            'chat_id' => $chatId,
            'photo' => $imagenUrl,
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
            ->timeout(25)
            ->connectTimeout(8)
            ->post($urlApi, $payload);

        $this->asegurarNoRateLimit($response);
        if ($response->successful()) {
            $messageId = $response->json('result.message_id');
            if ($messageId !== null && is_numeric($messageId)) {
                $this->registrarMensajeOferta($chatId, (int) $messageId, $productoId);
            }
            return;
        }

        // Fallback: Telegram no pudo descargar la URL (ej. CDN bloquea a Telegram). Descargamos nosotros y enviamos multipart.
        Log::info('NotificadorTelegram: sendPhoto(URL) falló; intentando envío por multipart (imagen descargada).', [
            'status' => $response->status(),
        ]);
        $contenido = $this->descargarImagenListado($imagenUrl);
        if ($contenido !== null && $contenido !== '') {
            $this->enviarFotoConCaptura($token, $chatId, $contenido, $caption, $urlAfiliado, $productoId);
        }
    }

    /**
     * Envía la foto (contenido binario) a Telegram. Para ML preferir enviarFotoConUrl (photo=URL).
     */
    private function enviarFotoConCaptura(string $token, string $chatId, string $contenidoImagen, string $caption, ?string $urlAfiliado, ?int $productoId = null): void
    {
        Log::info('DEBUG IMAGEN:', ['url' => '(binario)', 'metodo' => 'sendPhoto_multipart']);
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

        [$nombreArchivo, $contentType] = $this->tipoImagenParaAdjuntoTelegram($contenidoImagen);
        $response = Http::withOptions(['verify' => false])
            ->timeout(20)
            ->connectTimeout(5)
            ->attach('photo', $contenidoImagen, $nombreArchivo, ['Content-Type' => $contentType])
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
     * Devuelve [nombreArchivo, Content-Type] para sendPhoto multipart. Telegram necesita el MIME correcto para mostrar la imagen.
     *
     * @return array{0: string, 1: string}
     */
    private function tipoImagenParaAdjuntoTelegram(string $contenido): array
    {
        if (strlen($contenido) >= 3 && str_starts_with($contenido, "\xFF\xD8\xFF")) {
            return ['captura-oferta.jpg', 'image/jpeg'];
        }
        if (strlen($contenido) >= 4 && str_starts_with($contenido, "\x89PNG")) {
            return ['captura-oferta.png', 'image/png'];
        }
        if (strlen($contenido) >= 3 && str_starts_with($contenido, "GIF")) {
            return ['captura-oferta.gif', 'image/gif'];
        }
        return ['captura-oferta.png', 'image/png'];
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
