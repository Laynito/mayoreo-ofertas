<?php

namespace App\Support;

use App\Models\Configuracion;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP para motores de rastreo (cerebro del ahorro de GB).
 * - Proxy SOLO para HTML (texto). Imágenes/fuentes por IP directa del VPS.
 * - Caché 10 min por URL para no repetir peticiones por proxy.
 * - Límite de redirecciones (max_redirects => 5) para evitar bucles que consuman datos.
 */
final class HttpRastreador
{
    /** TTL por defecto de la caché de respuestas proxy (segundos). */
    public const CACHE_PROXY_TTL = 600;

    /**
     * Cabeceras para peticiones con proxy: Accept solo text/html (evita webp/avif y reduce GB).
     *
     * @return array<string, string>
     */
    public static function headersNavegador(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'es-MX,es;q=0.9',
            'Referer' => 'https://www.google.com.mx/',
        ];
    }

    /**
     * Cabeceras para Mercado Libre (y peticiones que solo necesitan el cuerpo HTML).
     * Solo text/html en Accept y Accept-Encoding para recibir HTML comprimido y reducir GB en proxy.
     *
     * @return array<string, string>
     */
    public static function headersSoloHtml(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9',
            'Accept-Encoding' => 'gzip, deflate',
            'Accept-Language' => 'es-MX,es;q=0.9',
            'Referer' => 'https://www.google.com.mx/',
            'DNT' => '1',
        ];
    }

    /**
     * Cabeceras para scraping web de tiendas (Walmart, Sam's, Costco): Referer = home de la tienda.
     * Reduce bloqueos Akamai/403 al simular navegación orgánica desde la propia tienda.
     *
     * @param  string  $refererBase  URL base de la tienda (ej. https://www.walmart.com.mx)
     * @return array<string, string>
     */
    public static function headersSoloHtmlConRefererTienda(string $refererBase): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Encoding' => 'gzip, deflate',
            'Accept-Language' => 'es-MX,es;q=0.9',
            'Referer' => rtrim($refererBase, '/') . '/',
            'DNT' => '1',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
        ];
    }

    /**
     * Indica si la URL es de Mercado Libre (página web, no API).
     */
    public static function esUrlMercadoLibre(string $url): bool
    {
        return str_contains($url, 'mercadolibre.com');
    }

    /**
     * Configuración base del cliente para proxy (Guzzle).
     * max_redirects => 5 evita bucles que consuman datos.
     *
     * @return array{verify: bool, connect_timeout: int, version: float, max_redirects: int, curl: array<int, mixed>}
     */
    public static function opcionesSslBase(): array
    {
        return [
            'verify' => false,
            'connect_timeout' => 60,
            'version' => 1.1,
            'max_redirects' => 5,
            'curl' => [
                \CURLOPT_SSLVERSION => \CURL_SSLVERSION_TLSv1_2,
                \CURLOPT_SSL_VERIFYHOST => 0,
                \CURLOPT_HTTPPROXYTUNNEL => 1,
                \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
                \CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
                \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4,
                \CURLOPT_TCP_KEEPALIVE => 1,
                \CURLOPT_BUFFERSIZE => 64000,
                \CURLOPT_EXPECT_100_TIMEOUT_MS => 0,
            ],
        ];
    }

    /**
     * Opciones para peticiones que usan proxy (SSL base + proxy URL).
     *
     * @param  string|null  $proxyUrlOverride  Si se pasa, se usa en lugar del proxy global (Ajustes / .env).
     * @return array{verify: bool, proxy?: string, curl: array<int, int>}
     */
    public static function opcionesProxy(?string $proxyUrlOverride = null): array
    {
        $proxy = $proxyUrlOverride ?? Configuracion::getProxyUrl();
        $opciones = self::opcionesSslBase();
        if ($proxy !== null && $proxy !== '') {
            $opciones['proxy'] = $proxy;
        }

        return $opciones;
    }

    /**
     * Aplica proxy + cabeceras + SSL. Opcional $proxyUrlOverride para tiendas con proxy distinto (ej. Amazon).
     */
    public static function conProxy(PendingRequest $request, ?string $proxyUrlOverride = null): PendingRequest
    {
        $proxy = $proxyUrlOverride ?? Configuracion::getProxyUrl();
        $opciones = self::opcionesSslBase();
        if ($proxy !== null && $proxy !== '') {
            $request = $request->withHeaders(self::headersNavegador());
            $opciones['proxy'] = $proxy;
        }

        return $request->withOptions($opciones);
    }

    /**
     * Proxy solo para URLs de texto (HTML). Imágenes/fuentes no pasan por proxy (ahorro de GB).
     * Para Mercado Libre se usan cabeceras "solo HTML" (headersSoloHtml) en el motor; aquí no se descarga imagen.
     *
     * @param  string|null  $proxyUrlOverride  Proxy específico por tienda (ej. config('services.proxy_url_amazon')).
     */
    public static function conProxySiTexto(PendingRequest $request, string $url, ?string $proxyUrlOverride = null): PendingRequest
    {
        if (self::esUrlDeImagen($url)) {
            return $request->withOptions(self::opcionesSslBase());
        }

        return self::conProxy($request, $proxyUrlOverride);
    }

    /**
     * Opciones Guzzle para peticiones a Mercado Libre: solo cuerpo HTML, sin seguir redirecciones a otros dominios.
     * Reduce consumo de GB al no descargar anuncios, fuentes ni scripts (una petición = un documento).
     *
     * @return array<string, mixed>
     */
    public static function opcionesSoloHtmlMl(): array
    {
        return [
            'max_redirects' => 5,
            'allow_redirects' => ['max' => 5, 'strict' => false],
        ];
    }

    /**
     * Caché de 10 min: si la URL ya se pidió, devuelve body/status desde caché (driver de Laravel) y evita usar el proxy.
     *
     * @param  callable(): array{body: string, status: int}  $fetcher
     * @return array{body: string, status: int}
     */
    public static function getCachedOrFetch(string $url, callable $fetcher, int $ttlSeconds = self::CACHE_PROXY_TTL): array
    {
        $key = 'proxy_html_' . md5($url);
        $cached = Cache::get($key);
        if (is_array($cached) && isset($cached['body'], $cached['status'])) {
            return $cached;
        }

        $result = $fetcher();
        if (is_array($result) && isset($result['body'], $result['status'])) {
            Cache::put($key, $result, $ttlSeconds);
        }

        return $result;
    }

    /**
     * Con proxy activo, devuelve http:// para api.mercadolibre.com (evita Error 35 en Hostinger).
     * El proxy hace HTTPS hacia ML; la salida desde el servidor es HTTP.
     */
    public static function urlApiMlParaProxy(string $url): string
    {
        if (Configuracion::getProxyUrl() === null) {
            return $url;
        }
        if (str_starts_with($url, 'https://api.mercadolibre.com')) {
            return 'http://api.mercadolibre.com' . substr($url, 28);
        }

        return $url;
    }

    /**
     * Indica si la URL apunta a un recurso de imagen (para no usar proxy y ahorrar datos).
     */
    public static function esUrlDeImagen(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === '') {
            return false;
        }
        $pathLower = strtolower($path);
        $extensiones = ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.avif', '.svg', '.bmp', '.ico'];
        foreach ($extensiones as $ext) {
            if (str_ends_with($pathLower, $ext) || str_contains($pathLower, $ext . '?')) {
                return true;
            }
        }
        if (preg_match('#/(?:image|img|imagen|assets|static)/[^/]+\.(?:jpg|jpeg|png|webp|gif|avif)#i', $pathLower)) {
            return true;
        }

        return false;
    }

    /**
     * Indica si la URL es un enlace corto de Mercado Libre (msmm.li u otros acortadores ML).
     */
    public static function esEnlaceCortoMercadoLibre(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === '') {
            return false;
        }
        $hostLower = strtolower($host);

        return $hostLower === 'msmm.li'
            || str_ends_with($hostLower, '.msmm.li')
            || str_contains($hostLower, 'mercadolibre.link');
    }

    /**
     * Expande un enlace corto (ej. msmm.li) con HEAD request siguiendo redirecciones.
     * Devuelve la URL final (Location) o la original si falla.
     */
    public static function expandirUrlCorta(string $url): string
    {
        if ($url === '' || ! self::esEnlaceCortoMercadoLibre($url)) {
            return $url;
        }

        $cacheKey = 'url_expandida_' . md5($url);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $request = Http::withHeaders(self::headersSoloHtml())
                ->timeout(15)
                ->connectTimeout(10)
                ->withOptions(self::opcionesSslBase());
            $request = self::conProxySiTexto($request, $url, Configuracion::getProxyUrl());

            $actual = $url;
            $maxPasos = 5;
            while ($maxPasos-- > 0) {
                $response = $request->noRedirect()->head($actual);
                if (! $response->redirect()) {
                    Cache::put($cacheKey, $actual, 3600);

                    return $actual;
                }
                $location = $response->header('Location');
                if (is_array($location)) {
                    $location = $location[0] ?? null;
                }
                if (! is_string($location) || $location === '' || ! str_starts_with($location, 'http')) {
                    break;
                }
                $actual = $location;
            }
            if ($actual !== $url) {
                Cache::put($cacheKey, $actual, 3600);

                return $actual;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('HttpRastreador: no se pudo expandir enlace corto', [
                'url' => $url,
                'mensaje' => $e->getMessage(),
            ]);
        }

        return $url;
    }

    /**
     * PendingRequest base (timeout por defecto) con proxy aplicado si existe.
     * Útil para motores que solo necesitan cabeceras y GET.
     */
    public static function cliente(): PendingRequest
    {
        $request = Http::timeout(15)->connectTimeout(10);

        return self::conProxy($request);
    }
}
