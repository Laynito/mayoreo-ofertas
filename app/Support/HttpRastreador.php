<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP para motores de rastreo.
 * Si PROXY_URL está definida en .env, todo el tráfico de texto (HTML/API) pasa por el proxy;
 * las imágenes se piden con IP local (ahorro de datos).
 */
final class HttpRastreador
{
    /**
     * Devuelve una instancia de Http (PendingRequest) con proxy aplicado cuando PROXY_URL está configurada.
     * Uso: HttpRastreador::conProxy(Http::withHeaders(...))->timeout(15)->get($url)
     */
    public static function conProxy(PendingRequest $request): PendingRequest
    {
        $proxy = config('services.proxy_url');
        if ($proxy !== null && $proxy !== '') {
            return $request->withOptions(['proxy' => $proxy]);
        }

        return $request;
    }

    /**
     * Aplica proxy solo para peticiones de texto (HTML, API, JSON). Las URLs de imagen se piden con IP local.
     * Uso en motores: HttpRastreador::conProxySiTexto($request, $url)->get($url)
     */
    public static function conProxySiTexto(PendingRequest $request, string $url): PendingRequest
    {
        if (self::esUrlDeImagen($url)) {
            return $request;
        }

        return self::conProxy($request);
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
