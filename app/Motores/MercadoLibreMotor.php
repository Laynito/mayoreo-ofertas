<?php

namespace App\Motores;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Mercado Libre México (API pública MLM).
 * Endpoint: sites/MLM/search?q=ofertas&sort=price_asc&filter_id=discount. Sin proxy (consumo eficiente).
 * Mapeo: title, price, original_price, thumbnail, permalink. Solo se notifica si descuento >15% (RastreoTiendaComando).
 * Fallback: scraping HTML de ofertas.
 */
class MercadoLibreMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.mercadolibre.com.mx';

    protected const RUTA_OFERTAS = 'ofertas';

    /** Endpoint principal API MLM (México): ofertas, orden por precio, solo con descuento real. Sin proxy. */
    private const API_BUSQUEDA = 'https://api.mercadolibre.com/sites/MLM/search';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Recolecta primero vía API MLM con filtro de ofertas; si falla, HTML.
     * Respeta permite_descuento_adicional (aplicado en RastreoTiendaComando al encolar).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $productos = $this->recolectarDesdeApi();
        if (! empty($productos)) {
            Log::info('MercadoLibreMotor: productos obtenidos vía API MLM', ['cantidad' => count($productos)]);
            return $productos;
        }

        return parent::recolectarDatos();
    }

    /**
     * Llama al endpoint principal MLM con filtros inteligentes (solo productos con descuento).
     * Sin proxy: la API permite miles de peticiones desde la IP del servidor sin bloqueo.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function recolectarDesdeApi(): array
    {
        $url = self::API_BUSQUEDA . '?q=ofertas&sort=price_asc&limit=50&filter_id=discount';
        $request = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0 Safari/537.36',
            'Accept' => 'application/json',
        ])->timeout(15);
        $respuesta = $request->get($url);

        if (! $respuesta->successful()) {
            Log::debug('MercadoLibreMotor: API no exitosa', ['status' => $respuesta->status()]);
            return [];
        }

        $data = $respuesta->json();
        $resultados = $data['results'] ?? [];
        if (! is_array($resultados)) {
            return [];
        }

        $productos = [];
        foreach (array_slice($resultados, 0, 50) as $item) {
            $normalizado = $this->normalizarItemApi($item);
            if ($normalizado !== null) {
                $productos[] = $normalizado;
            }
        }

        return $productos;
    }

    /**
     * Mapeo de datos API: title, price (precio con descuento), original_price, thumbnail (imagen), permalink (URL).
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItemApi(array $item): ?array
    {
        $id = (string) ($item['id'] ?? '');
        $nombre = (string) ($item['title'] ?? '');
        if ($id === '' && $nombre === '') {
            return null;
        }
        $skuTienda = 'ML-' . ($id ?: substr(md5($nombre), 0, 12));
        $precioOriginal = (float) ($item['original_price'] ?? $item['price'] ?? 0);
        $precioOferta = (float) ($item['price'] ?? 0);
        if ($precioOriginal <= 0) {
            $precioOriginal = $precioOferta;
        }
        $imagenUrl = $item['thumbnail'] ?? $item['thumbnail_id'] ?? null;
        if ($imagenUrl !== null && ! is_string($imagenUrl)) {
            $imagenUrl = null;
        }
        $urlOriginal = $item['permalink'] ?? null;
        if ($urlOriginal !== null && ! str_starts_with((string) $urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim((string) $urlOriginal, '/');
        }

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Mercado Libre',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 && $precioOferta < $precioOriginal ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
    }

    /**
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerProductosDeRespuesta(string $body, string $urlPagina): array
    {
        $productos = [];
        if (preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.+?)<\/script>/s', $body, $coincidencias)) {
            $json = json_decode(trim($coincidencias[1]), true);
            if (is_array($json)) {
                $productos = $this->mapearDesdeNextData($json);
            }
        }
        if (empty($productos)) {
            Log::debug('MercadoLibreMotor: no se extrajeron productos desde HTML (posible bloqueo o cambio de estructura).');
        }

        return $productos;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdeNextData(array $data): array
    {
        $items = $data['props']['pageProps']['initialState']['results']['results'] ?? $data['props']['pageProps']['results'] ?? [];
        if (! is_array($items)) {
            return [];
        }
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            $m = $this->normalizarItem($item);
            if ($m !== null) {
                $productos[] = $m;
            }
        }

        return $productos;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItem(array $item): ?array
    {
        $id = (string) ($item['id'] ?? $item['item_id'] ?? '');
        $nombre = (string) ($item['title'] ?? $item['name'] ?? '');
        if ($id === '' && $nombre === '') {
            return null;
        }
        $sku = 'ML-' . ($id ?: substr(md5($nombre), 0, 12));
        $precio = (float) ($item['price'] ?? $item['original_price'] ?? 0);
        $precioOferta = (float) ($item['sale_price'] ?? $item['price'] ?? 0);
        if ($precioOferta <= 0) {
            $precioOferta = $precio;
        }
        $imagenUrl = $item['thumbnail'] ?? $item['picture'] ?? null;
        $urlOriginal = $item['permalink'] ?? $item['url'] ?? null;
        if (is_string($urlOriginal) && ! str_starts_with($urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim($urlOriginal, '/');
        }

        return [
            'sku_tienda' => $sku,
            'nombre' => $nombre ?: 'Producto Mercado Libre',
            'precio_original' => round($precio > 0 ? $precio : $precioOferta, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
    }
}
