<?php

namespace App\Motores;

use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para AliExpress (México / español).
 * Enfocado en Super Ofertas (wholesale-superdeals).
 * Respeta permite_descuento_adicional (aplicado en RastreoTiendaComando al encolar).
 */
class AliExpressMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://es.aliexpress.com';

    /** Super Ofertas / Super Deals. */
    protected const RUTA_OFERTAS = 'w/wholesale-superdeals.html';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Extrae productos: scripts JSON (runParams, window.__NUXT__), luego JSON-LD o DOM.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerProductosDeRespuesta(string $body, string $urlPagina): array
    {
        $productos = $this->extraerDesdeRunParams($body);
        if (! empty($productos)) {
            return array_slice($productos, 0, 50);
        }

        $productos = $this->extraerDesdeNuxt($body);
        if (! empty($productos)) {
            return array_slice($productos, 0, 50);
        }

        Log::debug('AliExpressMotor: no se extrajeron productos (posible bloqueo o cambio de estructura).');

        return [];
    }

    /**
     * Busca runParams o datos de listado en scripts de la página.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeRunParams(string $body): array
    {
        $productos = [];
        if (preg_match('/"items"\s*:\s*(\[[\s\S]*?\])\s*[,}]/m', $body, $m)) {
            $items = json_decode($m[1], true);
            if (is_array($items)) {
                foreach (array_slice($items, 0, 50) as $item) {
                    $n = $this->normalizarItemAliExpress($item);
                    if ($n !== null) {
                        $productos[] = $n;
                    }
                }
            }
        }
        if (empty($productos) && preg_match('/"productId"\s*:\s*"(\d+)"[\s\S]*?"title"\s*:\s*"([^"]*)"[\s\S]*?"salePrice"\s*:\s*"?([\d.]+)"?/m', $body, $m)) {
            $productos = [];
            preg_match_all('/"productId"\s*:\s*"(\d+)"[\s\S]*?"title"\s*:\s*"([^"]*)"[\s\S]*?"salePrice"\s*:\s*"?([\d.]+)"?/m', $body, $todas, PREG_SET_ORDER);
            foreach (array_slice($todas, 0, 50) as $row) {
                $productos[] = [
                    'sku_tienda' => 'ALI-' . $row[1],
                    'nombre' => str_replace('\u0026', '&', $row[2]),
                    'precio_original' => round((float) $row[3] * 1.1, 2),
                    'precio_oferta' => round((float) $row[3], 2),
                    'imagen_url' => null,
                    'url_original' => self::URL_BASE . '/item/' . $row[1] . '.html',
                ];
            }
        }

        return $productos;
    }

    /**
     * Busca window.__NUXT__ o similar (Vue/Nuxt hidratación).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeNuxt(string $body): array
    {
        $productos = [];
        if (preg_match('/<script>window\.__NUXT__\s*=\s*(\{.+\});<\/script>/s', $body, $m)) {
            $json = json_decode($m[1], true);
            if (is_array($json)) {
                $items = $json['data'] ?? $json['state'] ?? $json['products'] ?? [];
                if (is_array($items)) {
                    foreach (array_slice($items, 0, 50) as $item) {
                        $n = $this->normalizarItemAliExpress($item);
                        if ($n !== null) {
                            $productos[] = $n;
                        }
                    }
                }
            }
        }

        return $productos;
    }

    /**
     * Normaliza ítem crudo de AliExpress (productId, title, salePrice, imageUrl, productDetailUrl).
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItemAliExpress(array $item): ?array
    {
        $id = (string) ($item['productId'] ?? $item['id'] ?? $item['product_id'] ?? '');
        $nombre = (string) ($item['title'] ?? $item['titleModule']['title'] ?? $item['name'] ?? '');
        if ($id === '' && $nombre === '') {
            return null;
        }
        $skuTienda = 'ALI-' . ($id ?: substr(md5($nombre), 0, 12));
        $precioOferta = isset($item['salePrice']) ? $this->precioANumero($item['salePrice']) : null;
        $precioOriginal = isset($item['originalPrice']) ? $this->precioANumero($item['originalPrice']) : $precioOferta;
        if ($precioOriginal === null) {
            $precioOriginal = $precioOferta ?? 0.0;
        }
        if ($precioOferta === null) {
            $precioOferta = $precioOriginal;
        }
        $imagenUrl = $item['imageUrl'] ?? $item['image'] ?? $item['thumbnail'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? null;
        }
        $urlOriginal = $item['productDetailUrl'] ?? $item['url'] ?? $item['permalink'] ?? null;
        if ($urlOriginal !== null && ! str_starts_with((string) $urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim((string) $urlOriginal, '/');
        }

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto AliExpress',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 && $precioOferta < $precioOriginal ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : (self::URL_BASE . '/item/' . $id . '.html'),
        ];
    }

    private function precioANumero(mixed $valor): ?float
    {
        if (is_numeric($valor)) {
            return (float) $valor;
        }
        $s = is_string($valor) ? preg_replace('/[^\d.]/', '', $valor) : '';

        return $s !== '' ? (float) $s : null;
    }
}
