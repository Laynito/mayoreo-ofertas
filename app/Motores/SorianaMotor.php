<?php

namespace App\Motores;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Soriana México.
 * Prioriza JSON embebido (Next.js / Vtex); si no hay, extrae por DOM (título, precio, imagen).
 */
class SorianaMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.soriana.com';

    /** Sección de promociones real. */
    protected const RUTA_OFERTAS = 'ofertas';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Extracción: primero JSON embebido (__NEXT_DATA__, __PRELOADED_STATE__, Vtex __STATE__), luego DOM con selectores básicos.
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

        if (empty($productos) && preg_match('/window\.__PRELOADED_STATE__\s*=\s*(\{.+\});/s', $body, $coincidencias)) {
            $json = json_decode($coincidencias[1], true);
            if (is_array($json)) {
                $productos = $this->mapearDesdePreloadedState($json);
            }
        }

        if (empty($productos) && preg_match('/__STATE__\s*=\s*(\{.+\})\s*;?\s*<\/script>/s', $body, $coincidencias)) {
            $productos = $this->mapearDesdeVtexState($coincidencias[1]);
        }

        if (empty($productos)) {
            $productos = $this->extraerDesdeDom($body);
        }

        if (empty($productos)) {
            $this->registrarRespuestaParaDebug($body, $urlPagina, 'SorianaMotor');
        } else {
            Log::info('SorianaMotor: productos extraídos', [
                'cantidad' => count($productos),
                'fuente' => 'JSON o DOM',
            ]);
        }

        return $productos;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdeNextData(array $data): array
    {
        $items = $data['props']['pageProps']['products'] ?? $data['props']['pageProps']['items'] ?? $data['props']['pageProps']['initialData']['products'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        return $this->mapearItems($items);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdePreloadedState(array $data): array
    {
        $items = $data['products'] ?? $data['search']['items'] ?? $data['productList'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        return $this->mapearItems($items);
    }

    /**
     * Vtex suele exponer __STATE__ con datos de productos.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdeVtexState(string $jsonStr): array
    {
        $data = json_decode($jsonStr, true);
        if (! is_array($data)) {
            return [];
        }
        $productos = [];
        $items = $data['search']['products'] ?? $data['productList'] ?? $data['products'] ?? [];
        if (is_array($items)) {
            $productos = $this->mapearItems($items);
        }

        return $productos;
    }

    /**
     * Extracción por DOM con selectores típicos de Soriana:
     * nombre: .product-name o similar; precio original: .price-old, .strike-through;
     * precio oferta: .price-sales, .pdp-price; SKU: data-pid.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeDom(string $body): array
    {
        $productos = [];
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        if (! @$dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR)) {
            return [];
        }
        $xpath = new DOMXPath($dom);

        // Contenedores: data-pid (Soriana), data-product-id, .product-card, .product-item, etc.
        $contenedores = $xpath->query("//*[@data-pid or @data-product-id or contains(@class, 'product-card') or contains(@class, 'product-item') or contains(@class, 'item-product') or contains(@class, 'product-tile')]");
        if ($contenedores === false || $contenedores->length === 0) {
            $contenedores = $xpath->query("//article[.//*[contains(@class, 'price') or contains(@class, 'precio')]]");
        }

        if ($contenedores === false || $contenedores->length === 0) {
            libxml_clear_errors();

            return [];
        }

        $vistos = [];
        foreach ($contenedores as $nodo) {
            $sku = $nodo instanceof \DOMElement
                ? trim($nodo->getAttribute('data-pid') ?: $nodo->getAttribute('data-product-id') ?: $nodo->getAttribute('data-sku') ?: '')
                : '';
            // Nombre: .product-name o similar (título, name, enlace producto)
            $nombre = $this->extraerTexto($xpath, $nodo, ".//*[contains(@class,'product-name')] | .//*[contains(@class,'product_name')] | .//h2 | .//*[contains(@class,'title')] | .//*[contains(@class,'name')] | .//a[contains(@href,'/p/')]");
            // Precio original: .price-old, .strike-through
            $precioOriginalTexto = $this->extraerTexto($xpath, $nodo, ".//*[contains(@class,'price-old')] | .//*[contains(@class,'strike-through')] | .//*[contains(@class,'price_old')] | .//s | .//del");
            // Precio oferta: .price-sales, .pdp-price
            $precioOfertaTexto = $this->extraerTexto($xpath, $nodo, ".//*[contains(@class,'price-sales')] | .//*[contains(@class,'pdp-price')] | .//*[contains(@class,'price_sales')] | .//*[contains(@class,'price') and not(contains(@class,'price-old'))] | .//*[contains(@class,'precio')] | .//*[@data-price]");
            $imagenUrl = $this->extraerImagen($xpath, $nodo);
            $urlOriginal = $this->extraerEnlace($xpath, $nodo);

            if ($nombre === '' && $precioOriginalTexto === '' && $precioOfertaTexto === '') {
                continue;
            }

            $precioOriginal = $this->parsearPrecio($precioOriginalTexto);
            $precioOferta = $this->parsearPrecio($precioOfertaTexto);
            if ($precioOriginal === null && $precioOferta !== null) {
                $precioOriginal = $precioOferta;
            }
            if ($precioOriginal === null) {
                $precioOriginal = 0.0;
            }

            $id = $sku ?: $nombre ?: $precioOriginalTexto . $precioOfertaTexto;
            if (isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;

            $productos[] = $this->normalizarItemSoriana([
                'sku' => $sku,
                'nombre' => $nombre,
                'precio_original' => $precioOriginal,
                'precio_oferta' => $precioOferta,
                'imagen_url' => $imagenUrl,
                'url_original' => $urlOriginal,
            ], true);
        }
        libxml_clear_errors();

        return array_slice(array_filter($productos), 0, 50);
    }

    private function extraerTexto(DOMXPath $xpath, \DOMNode $nodo, string $expr): string
    {
        $nodes = $xpath->query($expr, $nodo);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim($nodes->item(0)->textContent ?? '');
    }

    private function extraerImagen(DOMXPath $xpath, \DOMNode $nodo): string
    {
        $imgs = $xpath->query(".//img[contains(@class,'product') or contains(@class,'thumbnail') or @data-src or @src]", $nodo);
        if ($imgs === false || $imgs->length === 0) {
            return '';
        }
        $el = $imgs->item(0);
        if (! $el instanceof \DOMElement) {
            return '';
        }
        $src = $el->getAttribute('data-src') ?: $el->getAttribute('src');
        if ($src !== '' && str_starts_with($src, '//')) {
            $src = 'https:' . $src;
        }

        return $src;
    }

    private function extraerEnlace(DOMXPath $xpath, \DOMNode $nodo): string
    {
        $links = $xpath->query(".//a[contains(@href,'/p/') or contains(@href,'producto')]", $nodo);
        if ($links === false || $links->length === 0) {
            return '';
        }
        $el = $links->item(0);
        if (! $el instanceof \DOMElement) {
            return '';
        }
        $href = trim($el->getAttribute('href') ?? '');
        if ($href !== '' && ! str_starts_with($href, 'http')) {
            $href = self::URL_BASE . '/' . ltrim($href, '/');
        }

        return $href;
    }

    private function parsearPrecio(string $texto): ?float
    {
        $texto = preg_replace('/[^\d.,]/', '', $texto);
        $texto = str_replace(',', '.', $texto);
        if ($texto === '') {
            return null;
        }

        return (float) $texto ?: null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearItems(array $items): array
    {
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            $m = $this->normalizarItemSoriana($item, false);
            if ($m !== null) {
                $productos[] = $m;
            }
        }

        return $productos;
    }

    /**
     * Normaliza un ítem (desde JSON o desde array crudo del DOM) al formato estándar que espera RastrearTienda/Producto.
     *
     * @param  array<string, mixed>  $item  Datos crudos (sku/name/price desde API o desde DOM).
     * @param  bool  $desdeDom  Si true, el ítem tiene claves precio_original, precio_oferta, nombre, etc. ya extraídas del DOM.
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItemSoriana(array $item, bool $desdeDom = false): ?array
    {
        if ($desdeDom) {
            $sku = (string) ($item['sku'] ?? '');
            $nombre = (string) ($item['nombre'] ?? '');
            $precioOriginal = (float) ($item['precio_original'] ?? 0);
            $precioOferta = isset($item['precio_oferta']) ? (float) $item['precio_oferta'] : null;
            $imagenUrl = $item['imagen_url'] ?? null;
            $urlOriginal = $item['url_original'] ?? null;
            if ($nombre === '' && $precioOriginal <= 0) {
                return null;
            }

            return [
                'sku_tienda' => 'SOR-' . ($sku ?: substr(md5($nombre ?: (string) $precioOriginal), 0, 12)),
                'nombre' => $nombre ?: 'Producto Soriana',
                'precio_original' => round($precioOriginal, 2),
                'precio_oferta' => $precioOferta !== null && $precioOferta > 0 ? round($precioOferta, 2) : null,
                'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
                'url_original' => $urlOriginal ? (string) $urlOriginal : null,
            ];
        }

        $sku = (string) ($item['sku'] ?? $item['productId'] ?? $item['id'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['title'] ?? '');
        if ($sku === '' && $nombre === '') {
            return null;
        }
        $skuTienda = 'SOR-' . ($sku ?: substr(md5($nombre), 0, 12));
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
            'nombre' => $nombre ?: 'Producto Soriana',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
    }

    /**
     * Cuando no se encuentran productos, registra en el log la respuesta para diagnóstico (contexto = motor).
     */
    protected function registrarRespuestaParaDebug(string $body, string $urlPagina, string $contexto = 'SorianaMotor'): void
    {
        $longitud = strlen($body);
        $tieneNextData = str_contains($body, '__NEXT_DATA__');
        $tienePreloadedState = str_contains($body, '__PRELOADED_STATE__');
        $tieneVtexState = str_contains($body, '__STATE__');
        $titulo = '';
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $body, $m)) {
            $titulo = trim(strip_tags($m[1]));
        }
        $inicio = mb_substr($body, 0, 800);

        Log::warning($contexto . ': extracción fallida. Respuesta para ajustar selectores.', [
            'url' => $urlPagina,
            'longitud_body' => $longitud,
            'tiene___NEXT_DATA__' => $tieneNextData,
            'tiene___PRELOADED_STATE__' => $tienePreloadedState,
            'tiene___STATE__vtex' => $tieneVtexState,
            'titulo_pagina' => $titulo,
            'inicio_html' => $inicio,
        ]);
    }
}
