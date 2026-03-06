<?php

namespace App\Motores;

use App\Support\HttpRastreador;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Sam's Club México.
 * Estrategia: solo scraping web (página pública de rebajas). Sin APIs internas (evita 403 Akamai/SSL).
 * Usa HttpRastreador::getCachedOrFetch y conProxySiTexto. Referer = home de la tienda.
 * Estructura preparada para inyección de afiliados: URLs limpias en url_original (normalizarUrlPublicaSams).
 */
class SamsClubMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.sams.com.mx';

    /** Página pública de rebajas (evita /api/v1/search/showcase que devuelve 403). */
    private const URL_REBAJAS = 'https://www.sams.com.mx/c/rebajas/cat1100001';

    /** Fallback si la ruta de rebajas cambia. */
    private const URL_REBAJAS_ALT = 'https://www.sams.com.mx/s/rebajas';

    private const URL_OFERTAS_ALT = 'https://www.sams.com.mx/c/ofertas-exclusivas';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return 'c/rebajas/cat1100001';
    }

    /**
     * Recolecta solo desde página web pública (getCachedOrFetch + conProxySiTexto). Sin API.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $headers = HttpRastreador::headersSoloHtmlConRefererTienda(self::URL_BASE);

        $urls = [self::URL_REBAJAS, self::URL_REBAJAS_ALT, self::URL_OFERTAS_ALT];
        foreach ($urls as $url) {
            try {
                $resultado = HttpRastreador::getCachedOrFetch($url, function () use ($url, $headers): array {
                    $request = Http::withHeaders($headers)->timeout(60)->connectTimeout(30)
                        ->withOptions(HttpRastreador::opcionesSslBase());
                    $request = HttpRastreador::conProxySiTexto($request, $url);
                    $respuesta = $request->get($url);

                    return ['body' => $respuesta->body(), 'status' => $respuesta->status()];
                }, HttpRastreador::CACHE_PROXY_TTL);

                if ($resultado['status'] < 200 || $resultado['status'] >= 300) {
                    if ($resultado['status'] === 403) {
                        Log::debug('SamsClubMotor: 403 en URL', ['url' => $url]);
                    }
                    continue;
                }

                $productos = $this->extraerProductosDeRespuesta($resultado['body'], $url);
                if (empty($productos)) {
                    $productos = $this->extraerProductosDesdeHtml($resultado['body'], $url);
                }
                if (! empty($productos)) {
                    Log::info('SamsClubMotor: productos desde página web', ['cantidad' => count($productos), 'url' => $url]);

                    return $productos;
                }
            } catch (\Throwable $e) {
                Log::warning('SamsClubMotor: error en petición', ['url' => $url, 'mensaje' => $e->getMessage()]);
            }
        }

        return [];
    }

    /**
     * Extracción desde __NEXT_DATA__ (HTML de Next.js).
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
            $this->registrarRespuestaParaDebug($body, $urlPagina, 200);
        }

        return $productos;
    }

    /**
     * Fallback: extracción desde DOM (ej. .product-item, .product-list-item).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerProductosDesdeHtml(string $body, string $urlPagina): array
    {
        $productos = [];
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        if (! @$dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR)) {
            libxml_clear_errors();

            return [];
        }
        $xpath = new DOMXPath($dom);

        $contenedores = $xpath->query("//*[contains(@class, 'product-item') or contains(@class, 'product-list-item') or contains(@class, 'productTile')]");
        if ($contenedores === false || $contenedores->length === 0) {
            $contenedores = $xpath->query("//*[contains(@class, 'product') and .//a[@href]]");
        }
        if ($contenedores === false || $contenedores->length === 0) {
            libxml_clear_errors();

            return [];
        }

        foreach ($contenedores as $nodo) {
            if (! $nodo instanceof \DOMElement) {
                continue;
            }
            $nombre = $this->extraerTexto($xpath, $nodo, './/*[contains(@class, "title") or contains(@class, "name")]');
            if ($nombre === '') {
                $nombre = $this->extraerTexto($xpath, $nodo, './/a');
            }
            $enlace = $this->extraerHref($xpath, $nodo, './/a[contains(@href, "sams.com.mx")]');
            $precioTexto = $this->extraerTexto($xpath, $nodo, './/*[contains(@class, "price")]');
            $precioOferta = $this->parsearPrecio($precioTexto);
            $precioOriginal = $precioOferta;
            $imagenUrl = $this->extraerImagenUrlConLazy($xpath, $nodo);

            if ($nombre === '' && $enlace === '') {
                continue;
            }

            $urlOriginal = $this->normalizarUrlPublicaSams($enlace !== '' ? (str_starts_with($enlace, 'http') ? $enlace : self::URL_BASE . '/' . ltrim($enlace, '/')) : null);
            $skuTienda = 'SAM-' . substr(md5($nombre ?: $enlace ?: uniqid('', true)), 0, 12);

            $productos[] = [
                'sku_tienda' => $skuTienda,
                'nombre' => $nombre ?: 'Producto Sam\'s Club',
                'precio_original' => round($precioOriginal, 2),
                'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
                'imagen_url' => $imagenUrl !== '' ? $imagenUrl : null,
                'url_original' => $urlOriginal,
            ];
        }
        libxml_clear_errors();

        return array_slice($productos, 0, 50);
    }

    private function extraerTexto(DOMXPath $xpath, \DOMNode $nodo, string $expr): string
    {
        $nodes = $xpath->query($expr, $nodo);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $t = $nodes->item(0)->textContent ?? '';

        return trim(preg_replace('/\s+/', ' ', $t));
    }

    private function extraerHref(DOMXPath $xpath, \DOMNode $nodo, string $expr): string
    {
        $nodes = $xpath->query($expr, $nodo);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $el = $nodes->item(0);

        return $el instanceof \DOMElement ? trim($el->getAttribute('href') ?? '') : '';
    }

    private function extraerSrc(DOMXPath $xpath, \DOMNode $nodo, string $expr): string
    {
        $nodes = $xpath->query($expr, $nodo);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $el = $nodes->item(0);

        return $el instanceof \DOMElement ? trim($el->getAttribute('src') ?? '') : '';
    }

    /**
     * Extrae URL de imagen probando src, data-src, data-srcset, srcset (Sam's usa lazy load).
     */
    private function extraerImagenUrlConLazy(DOMXPath $xpath, \DOMNode $nodo): string
    {
        $nodes = $xpath->query('.//img', $nodo);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $el = $nodes->item(0);
        if (! $el instanceof \DOMElement) {
            return '';
        }
        $attrs = ['src', 'data-src', 'data-srcset', 'srcset'];
        foreach ($attrs as $attr) {
            $v = trim((string) $el->getAttribute($attr));
            if ($v !== '') {
                if (($attr === 'data-srcset' || $attr === 'srcset') && str_contains($v, ',')) {
                    $v = trim(explode(',', $v)[0]);
                    if (preg_match('/^(\S+)/', $v, $m)) {
                        $v = $m[1];
                    }
                }
                if ($v !== '') {
                    return $this->normalizarUrlImagenSams($v);
                }
            }
        }
        return '';
    }

    private function normalizarUrlImagenSams(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, '/')) {
            return self::URL_BASE . $url;
        }
        return self::URL_BASE . '/' . ltrim($url, '/');
    }

    private function parsearPrecio(string $texto): float
    {
        if (preg_match('/[\d,]+\.?\d*/', $texto, $m)) {
            return (float) str_replace(',', '', $m[0]);
        }

        return 0.0;
    }

    /**
     * URLs públicas para auditoría; preparado para inyección de afiliado (impact/awin).
     */
    protected function normalizarUrlPublicaSams(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (str_contains($url, '/api/') || str_contains($url, 'myvtex.com')) {
            return null;
        }
        if (! str_starts_with($url, 'http')) {
            $url = self::URL_BASE . '/' . ltrim($url, '/');
        }

        return str_starts_with($url, 'https://www.sams.com.mx') ? $url : null;
    }

    protected function registrarRespuestaParaDebug(string $body, string $urlPagina, int $status = 0): void
    {
        $longitud = strlen($body);
        $tieneNextData = str_contains($body, '__NEXT_DATA__');
        $titulo = '';
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $body, $m)) {
            $titulo = trim(strip_tags($m[1]));
        }
        $inicio = mb_substr($body, 0, 800);
        Log::warning('SamsClubMotor: extracción fallida. Respuesta para ajustar selectores.', [
            'url' => $urlPagina,
            'status' => $status,
            'longitud_body' => $longitud,
            'tiene___NEXT_DATA__' => $tieneNextData,
            'titulo_pagina' => $titulo,
            'inicio_html' => $inicio,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdeNextData(array $data): array
    {
        $items = $data['props']['pageProps']['products'] ?? $data['props']['pageProps']['items'] ?? null;
        if (! is_array($items)) {
            $items = $data['props']['pageProps']['initialState'] ?? null;
            if (is_array($items)) {
                $items = $items['products'] ?? $items['items'] ?? $items['searchResult']['products'] ?? $items['productSummaries'] ?? [];
            }
        }
        if (! is_array($items)) {
            $items = $data['props']['initialState'] ?? [];
            if (is_array($items)) {
                $items = $items['products'] ?? $items['items'] ?? $items['search'] ?? [];
            }
        }
        if (! is_array($items)) {
            return [];
        }
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            if (! is_array($item)) {
                continue;
            }
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
        $skuTienda = 'SAM-' . ($sku ?: substr(md5($nombre), 0, 12));
        $precioOriginal = (float) ($item['listPrice'] ?? $item['regularPrice'] ?? 0);
        $precioOferta = (float) ($item['salePrice'] ?? $item['price'] ?? 0);
        if ($precioOriginal <= 0) {
            $precioOriginal = $precioOferta;
        }
        $imagenUrl = $item['image'] ?? $item['imageUrl'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? null;
        }
        $urlOriginal = $this->normalizarUrlPublicaSams($item['url'] ?? $item['link'] ?? null);

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Sam\'s Club',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal,
        ];
    }
}
