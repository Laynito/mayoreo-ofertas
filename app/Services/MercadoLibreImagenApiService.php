<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Producto;
use App\Support\HttpRastreador;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Obtiene la URL de la imagen de un producto ML vía API (items o products para catálogo /p/MLM...).
 * Usado por NotificadorTelegram y por el comando ml:rellenar-imagen.
 */
final class MercadoLibreImagenApiService
{
    /**
     * Obtiene la URL de la imagen del producto desde la API de Mercado Libre.
     * Intenta GET /items/{id}; si devuelve 404 (ej. ID de catálogo /p/MLM...), intenta GET /products/{id}.
     *
     * @return string|null URL de la imagen o null si no hay token, no se puede extraer el ID o la API no devuelve imagen.
     */
    public static function getImagenUrl(Producto $producto): ?string
    {
        $token = MercadoLibreTokenService::obtenerAccessTokenValido();
        if ($token === null || $token === '') {
            return null;
        }
        $urlOriginal = $producto->url_original ?? '';
        $itemId = MercadoLibreShortUrlService::extraerItemId($urlOriginal, $producto->sku_tienda ?? null);
        if ($itemId === null || $itemId === '') {
            Log::debug('MercadoLibreImagenApiService: no se pudo extraer ID', [
                'url_original' => $urlOriginal,
                'sku_tienda' => $producto->sku_tienda,
            ]);
            return null;
        }
        $apiBase = Configuracion::getProxyUrl() !== null
            ? HttpRastreador::urlApiMlParaProxy('https://api.mercadolibre.com')
            : 'https://api.mercadolibre.com';
        $client = Http::withToken($token)->withHeaders(['Accept' => 'application/json'])->timeout(10);
        $client = HttpRastreador::conProxy($client);
        try {
            $response = $client->get($apiBase . '/items/' . $itemId);
            if ($response->successful()) {
                $url = self::extraerUrlImagenDesdeRespuesta($response->json());
                if ($url !== null) {
                    return $url;
                }
            }
            if ($response->status() === 404) {
                $url = self::getImagenUrlDesdeProducts($client, $apiBase, $itemId);
                if ($url !== null) {
                    return $url;
                }
            }
            return null;
        } catch (\Throwable $e) {
            Log::debug('MercadoLibreImagenApiService: error', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrae URL de imagen desde la respuesta JSON de Items o Products (thumbnail, secure_thumbnail, pictures).
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function extraerUrlImagenDesdeRespuesta(?array $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }
        $thumbnail = $data['secure_thumbnail'] ?? $data['thumbnail'] ?? $data['thumbnail_id'] ?? null;
        if (is_string($thumbnail) && $thumbnail !== '') {
            if (! str_starts_with($thumbnail, 'http')) {
                $thumbnail = 'https://http2.mlstatic.com/D_' . ltrim($thumbnail, '/') . '-O.jpg';
            }
            return $thumbnail;
        }
        $pictures = $data['pictures'] ?? $data['images'] ?? null;
        if (is_array($pictures) && isset($pictures[0])) {
            $first = $pictures[0];
            $url = is_array($first) ? ($first['secure_url'] ?? $first['url'] ?? null) : null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        return null;
    }

    /**
     * Fallback para IDs de catálogo (ej. /p/MLM...): GET /products/{id}.
     */
    private static function getImagenUrlDesdeProducts(\Illuminate\Http\Client\PendingRequest $client, string $apiBase, string $productId): ?string
    {
        try {
            $response = $client->get($apiBase . '/products/' . $productId);
            if (! $response->successful()) {
                return null;
            }
            return self::extraerUrlImagenDesdeRespuesta($response->json());
        } catch (\Throwable $e) {
            Log::debug('MercadoLibreImagenApiService: API products sin imagen', ['product_id' => $productId, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
