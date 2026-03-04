<?php

namespace App\Motores;

use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Mercado Libre México.
 * Usa Guzzle vía BaseMotorRastreador.
 */
class MercadoLibreMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.mercadolibre.com.mx';

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
        return 'mercado_libre';
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
            Log::debug('MercadoLibreMotor: no se extrajeron productos (posible bloqueo o cambio de estructura).');
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
