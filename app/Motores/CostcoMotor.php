<?php

namespace App\Motores;

use App\Support\HttpRastreador;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Costco México.
 * Estrategia: solo scraping web (página pública de ofertas). Sin APIs internas (evita 403 Akamai/SSL).
 * Usa HttpRastreador::getCachedOrFetch y conProxySiTexto. Referer = home de la tienda.
 * Estructura preparada para inyección de afiliados: URLs limpias en url_original (normalizarUrlPublicaCostco).
 */
class CostcoMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.costco.com.mx';

    /** Página pública de ofertas (evita /api/v1/search que devuelve 403). */
    private const URL_OFERTAS = 'https://www.costco.com.mx/ofertas';

    private const URL_OFERTAS_ALT = 'https://www.costco.com.mx/c/ofertas';

    private const URL_TREASURE_HUNT = 'https://www.costco.com.mx/treasure-hunt';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return 'ofertas';
    }

    /**
     * Recolecta solo desde página web pública (getCachedOrFetch + conProxySiTexto). Sin API.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $headers = HttpRastreador::headersSoloHtmlConRefererTienda(self::URL_BASE);

        $urls = [self::URL_OFERTAS, self::URL_OFERTAS_ALT, self::URL_TREASURE_HUNT];
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
                        Log::debug('CostcoMotor: 403 en URL', ['url' => $url]);
                    }
                    continue;
                }

                $productos = $this->extraerProductosDeRespuesta($resultado['body'], $url);
                if (empty($productos)) {
                    $productos = $this->extraerProductosDesdeHtml($resultado['body'], $url);
                }
                if (! empty($productos)) {
                    Log::info('CostcoMotor: productos desde página web', ['cantidad' => count($productos), 'url' => $url]);

                    return $productos;
                }
            } catch (\Throwable $e) {
                Log::warning('CostcoMotor: error en petición', ['url' => $url, 'mensaje' => $e->getMessage()]);
            }
        }

        return [];
    }

    /**
     * Extracción: __NEXT_DATA__ y __PRELOADED_STATE__.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerProductosDeRespuesta(string $body, string $urlPagina): array
    {
        $productos = [];
        if (preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.+?)<\/script>/s', $body, $coincidencias)) {
            $json = json_decode(trim($coincidencias[1]), true);
            if (is_array($json)) {
                $productos = $this->mapearDesdeNextData($json, $urlPagina);
            }
        }
        if (empty($productos) && preg_match('/<script[^>]*>[\s\S]*?window\.__PRELOADED_STATE__\s*=\s*(\{[\s\S]*?\});?\s*<\/script>/', $body, $preloaded)) {
            $data = json_decode($preloaded[1], true);
            if (is_array($data)) {
                $productos = $this->mapearDesdePreloadedState($data);
            }
        }
        if (empty($productos)) {
            Log::debug('CostcoMotor: no se extrajeron productos desde __NEXT_DATA__/__PRELOADED_STATE__', ['url' => $urlPagina]);
        }

        return $productos;
    }

    /**
     * Fallback: extracción desde DOM (.product-item, .product-list-item).
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
            $nombre = $this->extraerTexto($xpath, $nodo, './/*[contains(@class, "title") or contains(@class, "name") or contains(@class, "description")]');
            if ($nombre === '') {
                $nombre = $this->extraerTexto($xpath, $nodo, './/a');
            }
            $enlace = $this->extraerHref($xpath, $nodo, './/a[contains(@href, "costco.com.mx")]');
            $precioTexto = $this->extraerTexto($xpath, $nodo, './/*[contains(@class, "price")]');
            $precioOferta = $this->parsearPrecio($precioTexto);
            $precioOriginal = $precioOferta;
            $imagenUrl = $this->extraerSrc($xpath, $nodo, './/img[@src]');

            if ($nombre === '' && $enlace === '') {
                continue;
            }

            $urlOriginal = $this->normalizarUrlPublicaCostco($enlace !== '' ? (str_starts_with($enlace, 'http') ? $enlace : self::URL_BASE . '/' . ltrim($enlace, '/')) : null);
            $skuTienda = 'COS-' . substr(md5($nombre ?: $enlace ?: uniqid('', true)), 0, 12);

            $productos[] = [
                'sku_tienda' => $skuTienda,
                'nombre' => $nombre ?: 'Producto Costco',
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
    protected function normalizarUrlPublicaCostco(?string $url): ?string
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

        return str_starts_with($url, 'https://www.costco.com.mx') ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdeNextData(array $data, string $urlPagina = ''): array
    {
        $props = $data['props']['pageProps'] ?? $data['props'] ?? [];
        $items = $props['products'] ?? $props['items'] ?? $props['productList'] ?? null;
        if (! is_array($items)) {
            $items = $props['initialData']['products'] ?? $props['initialData']['items'] ?? $props['initialData']['productList'] ?? null;
        }
        if (! is_array($items)) {
            $items = $props['data']['products'] ?? $props['data']['items'] ?? null;
        }
        if (! is_array($items)) {
            $initial = $props['initialState'] ?? $data['props']['initialState'] ?? [];
            $items = $initial['products'] ?? $initial['items'] ?? $initial['searchResult']['products'] ?? [];
        }
        if (! is_array($items) && isset($props['initialData']['itemStacks'][0]['items'])) {
            $items = $props['initialData']['itemStacks'][0]['items'];
        }
        if (! is_array($items)) {
            $items = $this->buscarListaProductosEnArray($data);
        }
        if (! is_array($items) || empty($items)) {
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
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>|null
     */
    protected function buscarListaProductosEnArray(array $data, int $nivel = 0): ?array
    {
        if ($nivel > 4) {
            return null;
        }
        $pareceListaProductos = function (array $arr): bool {
            if (count($arr) === 0) {
                return false;
            }
            $first = reset($arr);
            if (! is_array($first)) {
                return false;
            }

            return isset($first['name'], $first['price'])
                || isset($first['title'], $first['price'])
                || isset($first['productName'], $first['salePrice'])
                || isset($first['productId'], $first['name']);
        };
        if ($pareceListaProductos($data)) {
            return $data;
        }
        foreach ($data as $value) {
            if (! is_array($value)) {
                continue;
            }
            $found = $this->buscarListaProductosEnArray($value, $nivel + 1);
            if ($found !== null && ! empty($found)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdePreloadedState(array $data): array
    {
        $items = $data['products'] ?? $data['items'] ?? $data['searchResult']['products'] ?? $data['productList'] ?? [];
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
        $sku = (string) ($item['sku'] ?? $item['productId'] ?? $item['id'] ?? $item['itemId'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['title'] ?? $item['productName'] ?? $item['description'] ?? '');
        if ($sku === '' && $nombre === '') {
            return null;
        }
        $skuTienda = 'COS-' . ($sku ?: substr(md5($nombre), 0, 12));
        $precioOriginal = (float) ($item['listPrice'] ?? $item['regularPrice'] ?? $item['originalPrice'] ?? 0);
        $precioOferta = (float) ($item['salePrice'] ?? $item['price'] ?? $item['currentPrice'] ?? 0);
        if ($precioOriginal <= 0) {
            $precioOriginal = $precioOferta;
        }
        $imagenUrl = $item['image'] ?? $item['imageUrl'] ?? $item['thumbnail'] ?? $item['picture'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl['src'] ?? $imagenUrl[0] ?? null;
        }
        $urlRaw = $item['url'] ?? $item['link'] ?? $item['permalink'] ?? $item['path'] ?? null;
        $urlOriginal = $this->normalizarUrlPublicaCostco(is_string($urlRaw) ? $urlRaw : null);

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Costco',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal,
        ];
    }
}
