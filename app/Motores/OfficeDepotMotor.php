<?php

namespace App\Motores;

use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Office Depot México.
 * Apunta a promociones del mes / ofertas (officedepot.com.mx).
 * Respeta permite_descuento_adicional (aplicado en RastreoTiendaComando al encolar).
 */
class OfficeDepotMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.officedepot.com.mx';

    /** Promociones del mes (página principal de ofertas). */
    protected const RUTA_OFERTAS = 'officedepot/en/promociones-del-mes';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Extrae productos: __NEXT_DATA__, JSON embebido (productGrid, products), luego DOM.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerProductosDeRespuesta(string $body, string $urlPagina): array
    {
        $productos = $this->extraerDesdeNextData($body);
        if (! empty($productos)) {
            return array_slice($productos, 0, 50);
        }

        $productos = $this->extraerDesdeJsonEmbebido($body);
        if (! empty($productos)) {
            return array_slice($productos, 0, 50);
        }

        $productos = $this->extraerDesdeDom($body);
        if (! empty($productos)) {
            return array_slice($productos, 0, 50);
        }

        Log::debug('OfficeDepotMotor: no se extrajeron productos (posible bloqueo o cambio de estructura).');

        return [];
    }

    /**
     * Busca __NEXT_DATA__ (Next.js) con datos de productos.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeNextData(string $body): array
    {
        if (! preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.+?)<\/script>/s', $body, $m)) {
            return [];
        }
        $json = json_decode(trim($m[1]), true);
        if (! is_array($json)) {
            return [];
        }
        $props = $json['props']['pageProps'] ?? $json['props'] ?? [];
        $items = $props['products'] ?? $props['productGrid'] ?? $props['initialState']['products'] ?? $props['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            $n = $this->normalizarItem($item);
            if ($n !== null) {
                $productos[] = $n;
            }
        }

        return $productos;
    }

    /**
     * Busca bloques JSON en script (productGrid, catalog, etc.).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeJsonEmbebido(string $body): array
    {
        $productos = [];
        if (preg_match('/"products"\s*:\s*(\[[\s\S]*?\])\s*[,}]/m', $body, $m)) {
            $items = json_decode($m[1], true);
            if (is_array($items)) {
                foreach (array_slice($items, 0, 50) as $item) {
                    $n = $this->normalizarItem($item);
                    if ($n !== null) {
                        $productos[] = $n;
                    }
                }
            }
        }
        if (empty($productos) && preg_match('/"productGrid"\s*:\s*(\[[\s\S]*?\])/m', $body, $m)) {
            $items = json_decode($m[1], true);
            if (is_array($items)) {
                foreach (array_slice($items, 0, 50) as $item) {
                    $n = $this->normalizarItem($item);
                    if ($n !== null) {
                        $productos[] = $n;
                    }
                }
            }
        }

        return $productos;
    }

    /**
     * Extracción por DOM: enlaces a producto y precios (clases típicas de e-commerce).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeDom(string $body): array
    {
        $productos = [];
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        if (! @$dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR)) {
            libxml_clear_errors();
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $enlaces = $xpath->query("//a[contains(@href,'/p/') or contains(@href,'/producto/') or contains(@href,'product-')]");
        if ($enlaces === false || $enlaces->length === 0) {
            libxml_clear_errors();
            return [];
        }
        $vistos = [];
        foreach ($enlaces as $a) {
            if (! $a instanceof \DOMElement) {
                continue;
            }
            $href = trim($a->getAttribute('href') ?? '');
            if ($href === '' || isset($vistos[$href])) {
                continue;
            }
            if (! str_contains($href, 'officedepot.com.mx') && ! str_starts_with($href, '/')) {
                continue;
            }
            $urlOriginal = str_starts_with($href, 'http') ? $href : rtrim(self::URL_BASE, '/') . '/' . ltrim($href, '/');
            $vistos[$href] = true;
            $nombre = trim($a->textContent ?? '');
            if (strlen($nombre) < 3) {
                $nombre = 'Producto Office Depot';
            }
            $sku = preg_replace('/[^\w-]/', '', parse_url($urlOriginal, PHP_URL_PATH) ?? '');
            $skuTienda = 'OD-' . ($sku !== '' ? substr($sku, 0, 32) : substr(md5($urlOriginal), 0, 12));
            $productos[] = [
                'sku_tienda' => $skuTienda,
                'nombre' => $nombre,
                'precio_original' => 0.0,
                'precio_oferta' => null,
                'imagen_url' => null,
                'url_original' => $urlOriginal,
            ];
        }
        libxml_clear_errors();

        return array_slice($productos, 0, 50);
    }

    /**
     * Normaliza ítem crudo (code/sku, name/title, price, basePrice, image, url).
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItem(array $item): ?array
    {
        $sku = (string) ($item['code'] ?? $item['sku'] ?? $item['id'] ?? $item['productId'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['title'] ?? $item['nombre'] ?? '');
        if ($nombre === '' && $sku === '') {
            return null;
        }
        $skuTienda = 'OD-' . ($sku ?: substr(md5($nombre), 0, 12));
        $precioOferta = $this->precioANumero($item['price'] ?? $item['salePrice'] ?? $item['currentPrice'] ?? null);
        $precioOriginal = $this->precioANumero($item['basePrice'] ?? $item['listPrice'] ?? $item['originalPrice'] ?? null) ?? $precioOferta;
        if ($precioOriginal === null) {
            $precioOriginal = $precioOferta ?? 0.0;
        }
        if ($precioOferta === null) {
            $precioOferta = $precioOriginal;
        }
        $imagenUrl = $item['image'] ?? $item['imageUrl'] ?? $item['thumbnail'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? null;
        }
        $urlOriginal = $item['url'] ?? $item['link'] ?? $item['permalink'] ?? null;
        if ($urlOriginal !== null && ! str_starts_with((string) $urlOriginal, 'http')) {
            $urlOriginal = rtrim(self::URL_BASE, '/') . '/' . ltrim((string) $urlOriginal, '/');
        }

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Office Depot',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 && $precioOferta < $precioOriginal ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
    }

    private function precioANumero(mixed $valor): ?float
    {
        if (is_numeric($valor)) {
            return (float) $valor;
        }
        if (is_string($valor)) {
            $s = preg_replace('/[^\d.]/', '', $valor);
            return $s !== '' ? (float) $s : null;
        }
        return null;
    }
}
