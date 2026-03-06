<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP para motores de rastreo.
 * Un solo método (conProxySiTexto/conProxy) aplica proxy + cabeceras de navegador + SSL base; no se aplican dos veces.
 * Si PROXY_URL está definida, el tráfico de texto pasa por el proxy; las imágenes con IP local.
 */
final class HttpRastreador
{
    /**
     * Cabeceras de navegador real para peticiones públicas (modo incógnito; sin token).
     *
     * @return array<string, string>
     */
    public static function headersNavegador(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Accept-Language' => 'es-MX,es;q=0.9',
            'Referer' => 'https://www.mercadolibre.com.mx/',
        ];
    }

    /**
     * Configuración base del cliente para proxy residencial (Smartproxy / Hostinger).
     * connect_timeout 60s, HTTP/1.1 forzado, TLS 1.2, cipher relajado.
     *
     * @return array{verify: bool, connect_timeout: int, version: float, curl: array<int, mixed>}
     */
    public static function opcionesSslBase(): array
    {
        return [
            'verify' => false,
            'connect_timeout' => 60,
            'version' => 1.1,
            'curl' => [
                \CURLOPT_SSLVERSION => \CURL_SSLVERSION_TLSv1_2,
                \CURLOPT_HTTPPROXYTUNNEL => 1,
                \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
                \CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
                \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4,
                \CURLOPT_TCP_KEEPALIVE => 1,
                \CURLOPT_BUFFERSIZE => 64000,
            ],
        ];
    }

    /**
     * Opciones para peticiones que usan proxy (SSL base + proxy URL). Para uso directo cuando no se usa conProxy.
     *
     * @return array{verify: bool, proxy?: string, curl: array<int, int>}
     */
    public static function opcionesProxy(): array
    {
        $proxy = config('services.proxy_url');
        $opciones = self::opcionesSslBase();
        if ($proxy !== null && $proxy !== '') {
            $opciones['proxy'] = $proxy;
        }

        return $opciones;
    }

    /**
     * Único punto que aplica proxy + cabeceras de navegador + SSL base. Las cabeceras de navegador solo se añaden cuando hay proxy.
     */
    public static function conProxy(PendingRequest $request): PendingRequest
    {
        $proxy = config('services.proxy_url');
        $opciones = self::opcionesSslBase();
        if ($proxy !== null && $proxy !== '') {
            $request = $request->withHeaders(self::headersNavegador());
            $opciones['proxy'] = $proxy;
        }

        return $request->withOptions($opciones);
    }

    /**
     * Aplica proxy (y headers + SSL) solo para URLs de texto. Las imágenes no pasan por proxy.
     */
    public static function conProxySiTexto(PendingRequest $request, string $url): PendingRequest
    {
        if (self::esUrlDeImagen($url)) {
            return $request->withOptions(self::opcionesSslBase());
        }

        return self::conProxy($request);
    }

    /**
     * Con proxy activo, devuelve http:// para api.mercadolibre.com (evita Error 35 en Hostinger).
     * El proxy hace HTTPS hacia ML; la salida desde el servidor es HTTP.
     */
    public static function urlApiMlParaProxy(string $url): string
    {
        if (config('services.proxy_url') === null || config('services.proxy_url') === '') {
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
     * PendingRequest base (timeout por defecto) con proxy aplicado si existe.
     * Útil para motores que solo necesitan cabeceras y GET.
     */
    public static function cliente(): PendingRequest
    {
        $request = Http::timeout(15)->connectTimeout(10);

        return self::conProxy($request);
    }
}
