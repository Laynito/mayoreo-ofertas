<?php

namespace App\Motores;

use App\Support\HttpRastreador;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Walmart México.
 * Estrategia: solo scraping web (página pública de ofertas). Sin APIs internas (evita 403 Akamai).
 * Usa HttpRastreador::getCachedOrFetch y conProxySiTexto. Referer = home de la tienda.
 * Estructura preparada para inyección de afiliados (impact/awin): URLs limpias en url_original.
 */
class WalmartMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.walmart.com.mx';

    /** Página pública de ofertas (evita /api/deals que devuelve 403). */
    private const URL_OFERTAS = 'https://www.walmart.com.mx/ofertas';

    /** Fallback: búsqueda "ofertas" por si /ofertas no existe. */
    private const URL_OFERTAS_FALLBACK = 'https://www.walmart.com.mx/search?q=ofertas';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return 'ofertas';
    }

    /**
     * Recolecta solo desde página web pública (getCachedOrFetch + conProxySiTexto).
     * Extracción: __NEXT_DATA__ / __PRELOADED_STATE__ y fallback a selectores HTML (data-automation-id="product-tile").
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $headers = HttpRastreador::headersSoloHtmlConRefererTienda(self::URL_BASE);

        $urls = [self::URL_OFERTAS, self::URL_OFERTAS_FALLBACK];
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
                    continue;
                }

                $productos = $this->extraerProductosDeRespuesta($resultado['body'], $url);
                if (empty($productos)) {
                    $productos = $this->extraerProductosDesdeHtml($resultado['body'], $url);
                }
                if (! empty($productos)) {
                    Log::info('WalmartMotor: productos desde página web', ['cantidad' => count($productos), 'url' => $url]);

                    return $productos;
                }
            } catch (\Throwable $e) {
                Log::warning('WalmartMotor: error en petición', ['url' => $url, 'mensaje' => $e->getMessage()]);
            }
        }

        return [];
    }

    /**
     * Extracción: __NEXT_DATA__, __PRELOADED_STATE__ (misma lógica que antes).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerProductosDeRespuesta(string $body, string $urlPagina): array
    {
        $productos = [];

        if (preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.+?)<\/script>/s', $body, $coincidencias)) {
            $json = json_decode(trim($coincidencias[1]), true);
            if (is_array($json)) {
                $productos = $this->mapearProductosDesdeNextData($json);
            }
        }

        if (empty($productos) && preg_match('/window\.__PRELOADED_STATE__\s*=\s*(\{.+\});/s', $body, $coincidencias)) {
            $json = json_decode($coincidencias[1], true);
            if (is_array($json)) {
                $productos = $this->mapearProductosDesdePreloadedState($json);
            }
        }

        if (empty($productos)) {
            $this->registrarRespuestaParaDebug($body, $urlPagina, 'WalmartMotor');
        }

        return $productos;
    }

    /**
     * Fallback: extrae desde HTML con selectores (ej. [data-automation-id="product-tile"]).
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

        $contenedores = $xpath->query("//*[@data-automation-id='product-tile']");
        if ($contenedores === false || $contenedores->length === 0) {
            $contenedores = $xpath->query("//*[contains(@class, 'product-tile') or contains(@class, 'productTile')]");
        }
        if ($contenedores === false || $contenedores->length === 0) {
            libxml_clear_errors();

            return [];
        }

        foreach ($contenedores as $nodo) {
            if (! $nodo instanceof \DOMElement) {
                continue;
            }
            $nombre = $this->extraerTexto($xpath, $nodo, ".//*[contains(@class, 'product-title') or @data-automation-id='product-title']");
            if ($nombre === '') {
                $nombre = $this->extraerTexto($xpath, $nodo, './/a[@href]');
            }
            $enlace = $this->extraerHref($xpath, $nodo, './/a[@href and contains(@href, "walmart.com.mx")]');
            $precioOriginal = $this->extraerPrecioDesdeNodo($xpath, $nodo, 'list-price', 'listPrice');
            $precioOferta = $this->extraerPrecioDesdeNodo($xpath, $nodo, 'current-price', 'currentPrice');
            if ($precioOferta <= 0) {
                $precioOferta = $precioOriginal;
            }
            if ($precioOriginal <= 0) {
                $precioOriginal = $precioOferta;
            }
            $imagenUrl = $this->extraerSrc($xpath, $nodo, './/img[contains(@src, "http")]');

            if ($nombre === '' && $enlace === '') {
                continue;
            }

            $urlOriginal = $this->normalizarUrlPublicaWalmart($enlace !== '' ? (str_starts_with($enlace, 'http') ? $enlace : self::URL_BASE . '/' . ltrim($enlace, '/')) : null);
            $skuTienda = 'WAL-' . substr(md5($nombre ?: $enlace ?: uniqid('', true)), 0, 12);

            $productos[] = [
                'sku_tienda' => $skuTienda,
                'nombre' => $nombre ?: 'Producto Walmart',
                'precio_original' => round($precioOriginal, 2),
                'precio_oferta' => $precioOferta > 0 && $precioOferta < $precioOriginal ? round($precioOferta, 2) : null,
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

    private function extraerPrecioDesdeNodo(DOMXPath $xpath, \DOMNode $nodo, string $dataAttr, string $classPart): float
    {
        $nodes = $xpath->query(".//*[contains(@class, '{$classPart}') or @data-automation-id='{$dataAttr}']", $nodo);
        if ($nodes === false || $nodes->length === 0) {
            return 0.0;
        }
        $texto = trim($nodes->item(0)->textContent ?? '');
        if (preg_match('/[\d,]+\.?\d*/', $texto, $m)) {
            return (float) str_replace(',', '', $m[0]);
        }

        return 0.0;
    }

    /**
     * URLs públicas para auditoría; preparado para inyección de afiliado (impact/awin) en el comando.
     */
    protected function normalizarUrlPublicaWalmart(?string $url): ?string
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

        return str_starts_with($url, 'https://www.walmart.com.mx') ? $url : null;
    }

    protected function registrarRespuestaParaDebug(string $body, string $urlPagina, string $motor): void
    {
        $longitud = strlen($body);
        $tieneNextData = str_contains($body, '__NEXT_DATA__');
        $tienePreloadedState = str_contains($body, '__PRELOADED_STATE__');
        $titulo = '';
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $body, $m)) {
            $titulo = trim(strip_tags($m[1]));
        }
        $inicio = mb_substr($body, 0, 800);

        Log::warning("{$motor}: extracción fallida. Respuesta para ajustar selectores.", [
            'url' => $urlPagina,
            'longitud_body' => $longitud,
            'tiene___NEXT_DATA__' => $tieneNextData,
            'tiene___PRELOADED_STATE__' => $tienePreloadedState,
            'titulo_pagina' => $titulo,
            'inicio_html' => $inicio,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearProductosDesdeNextData(array $data): array
    {
        $props = $data['props']['pageProps'] ?? $data['props'] ?? [];
        $items = $props['initialData']['searchResult']['itemStacks'][0]['items'] ?? $props['products'] ?? [];

        return $this->mapearItems($items);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearProductosDesdePreloadedState(array $data): array
    {
        $items = $data['products'] ?? $data['search']['items'] ?? $data['itemStacks'][0]['items'] ?? [];

        return $this->mapearItems($items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearItems(array $items): array
    {
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            $mapeado = $this->normalizarItemWalmart($item);
            if ($mapeado !== null) {
                $productos[] = $mapeado;
            }
        }

        return $productos;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItemWalmart(array $item): ?array
    {
        $sku = (string) ($item['id'] ?? $item['sku'] ?? $item['productId'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['title'] ?? $item['nombre'] ?? '');

        if ($sku === '' && $nombre === '') {
            return null;
        }

        $skuTienda = 'WAL-' . ($sku ?: substr(md5($nombre), 0, 12));

        $precioOriginal = isset($item['listPrice']) ? (float) $item['listPrice'] : (float) ($item['price'] ?? 0);
        $precioOferta = null;
        if (isset($item['currentPrice']) && (float) $item['currentPrice'] > 0) {
            $precioOferta = (float) $item['currentPrice'];
        } elseif (isset($item['salePrice'])) {
            $precioOferta = (float) $item['salePrice'];
        }
        if ($precioOriginal <= 0 && $precioOferta !== null) {
            $precioOriginal = $precioOferta;
        }

        $imagenUrl = null;
        if (! empty($item['image'])) {
            $imagenUrl = is_string($item['image']) ? $item['image'] : ($item['image']['url'] ?? null);
        }
        $imagenUrl = $imagenUrl ?? $item['thumbnailUrl'] ?? $item['imageUrl'] ?? null;
        if (is_string($imagenUrl) && str_starts_with($imagenUrl, '//')) {
            $imagenUrl = 'https:' . $imagenUrl;
        }

        $urlOriginal = $item['url'] ?? $item['productUrl'] ?? null;
        if (is_string($urlOriginal) && ! str_starts_with($urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim($urlOriginal, '/');
        }

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Walmart',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta !== null ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $this->normalizarUrlPublicaWalmart($urlOriginal),
        ];
    }
}
