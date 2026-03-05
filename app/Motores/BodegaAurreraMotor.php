<?php

namespace App\Motores;

use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Bodega Aurrera (Walmart México).
 * Extrae ofertas desde __NEXT_DATA__ (Next.js). SKU con prefijo BA-.
 *
 * Regla de Oro: Si en Filament el interruptor "Permitir descuento adicional" está apagado
 * para ese producto, el sistema (CalculadoraOfertas + NotificadorTelegram) envía el precio
 * de la tienda tal cual a Telegram, protegiendo el margen.
 */
class BodegaAurreraMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.bodegaaurrera.com.mx';

    protected const RUTA_OFERTAS = 'especiales/todas-las-ofertas';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Extrae productos del JSON dentro de __NEXT_DATA__.
     *
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
            Log::info('BodegaAurreraMotor: no se encontró __NEXT_DATA__ o lista vacía', ['url' => $urlPagina]);
        } else {
            Log::info('BodegaAurreraMotor: productos extraídos', [
                'cantidad' => count($productos),
                'fuente' => '__NEXT_DATA__',
            ]);
        }

        return $productos;
    }

    /**
     * Obtiene la lista de ítems desde la estructura típica de Next.js (pageProps).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdeNextData(array $data): array
    {
        $props = $data['props']['pageProps'] ?? [];
        $items = $props['products'] ?? $props['items'] ?? $props['initialData']['products'] ?? $props['initialData']['items'] ?? [];
        if (isset($props['initialData']['itemStacks'][0]['items'])) {
            $items = $props['initialData']['itemStacks'][0]['items'];
        }
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
     * Normaliza un ítem: Nombre, Precio Lista (original), Precio Oferta, Imagen. Prefijo SKU: BA-.
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItem(array $item): ?array
    {
        $sku = (string) ($item['id'] ?? $item['sku'] ?? $item['productId'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['title'] ?? $item['productName'] ?? '');
        if ($sku === '' && $nombre === '') {
            return null;
        }

        $skuTienda = 'BA-' . ($sku ?: substr(md5($nombre), 0, 12));

        // Precio Lista (original) y Precio Oferta
        $precioLista = (float) ($item['listPrice'] ?? $item['regularPrice'] ?? $item['price'] ?? 0);
        $precioOferta = (float) ($item['currentPrice'] ?? $item['salePrice'] ?? $item['offerPrice'] ?? $item['price'] ?? 0);
        if ($precioLista <= 0) {
            $precioLista = $precioOferta;
        }

        // Imagen
        $imagenUrl = $item['image'] ?? $item['thumbnailUrl'] ?? $item['imageUrl'] ?? $item['images'][0] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl['src'] ?? $imagenUrl[0] ?? null;
            if (is_array($imagenUrl)) {
                $imagenUrl = $imagenUrl['url'] ?? $imagenUrl['src'] ?? null;
            }
        }

        $urlOriginal = $item['url'] ?? $item['productUrl'] ?? $item['link'] ?? null;
        if (is_string($urlOriginal) && $urlOriginal !== '' && ! str_starts_with($urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim($urlOriginal, '/');
        }

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Bodega Aurrera',
            'precio_original' => round($precioLista, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
    }
}
