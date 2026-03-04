<?php

namespace App\Motores;

use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Chedraui México.
 * Usa Guzzle vía BaseMotorRastreador.
 */
class ChedrauiMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.chedraui.com.mx';

    protected const RUTA_OFERTAS = 'ofertas';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    protected function getClaveConfigProxy(): ?string
    {
        return 'chedraui';
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
            Log::debug('ChedrauiMotor: no se extrajeron productos (posible bloqueo o cambio de estructura).');
        }

        return $productos;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdeNextData(array $data): array
    {
        $items = $data['props']['pageProps']['products'] ?? $data['props']['pageProps']['items'] ?? [];
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
        $sku = (string) ($item['sku'] ?? $item['productId'] ?? $item['id'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['title'] ?? '');
        if ($sku === '' && $nombre === '') {
            return null;
        }
        $skuTienda = 'CHD-' . ($sku ?: substr(md5($nombre), 0, 12));
        $precioOriginal = (float) ($item['listPrice'] ?? $item['regularPrice'] ?? 0);
        $precioOferta = (float) ($item['salePrice'] ?? $item['price'] ?? 0);
        if ($precioOriginal <= 0) {
            $precioOriginal = $precioOferta;
        }
        $imagenUrl = $item['image'] ?? $item['imageUrl'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? null;
        }
        $urlOriginal = $item['url'] ?? $item['link'] ?? null;
        if (is_string($urlOriginal) && ! str_starts_with($urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim($urlOriginal, '/');
        }

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Chedraui',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
    }
}
