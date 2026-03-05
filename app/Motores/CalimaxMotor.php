<?php

namespace App\Motores;

use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Calimax (Tijuana / Baja California).
 * Sitio: tienda.calimax.com.mx (VTEX). Extrae nombre, precio actual, precio original, imagen y categoría.
 * Basado en la estructura de CoppelMotor: bulk insert, historial, NotificadorTelegram (Premium/Free/Teaser).
 */
class CalimaxMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://tienda.calimax.com.mx';

    protected const RUTA_OFERTAS = 'especiales?map=productclusternames';

    /** Prefijo para imágenes VTEX cuando vienen relativas. */
    protected const IMAGEN_BASE = 'https://calimaxmx.vtexassets.com';

    /** Máximo de productos a extraer por página/sección. */
    protected const MAX_PRODUCTOS = 200;

    /** API de búsqueda VTEX (JSON puro, no depende de JS en el HTML). */
    protected const API_SEARCH = '/api/catalog_system/pub/products/search';

    /** ID de cluster de especiales en VTEX (prioritario). Si no devuelve nada, se intenta sin fq. */
    protected const CLUSTER_ESPECIALES = 150;

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Cabeceras con Referer fijo. Para peticiones a la API, headers de validación para evitar bloqueos.
     *
     * @return array<string, mixed>
     */
    protected function getOpcionesPeticion(string $url): array
    {
        $headers = [
            'Referer' => 'https://tienda.calimax.com.mx/',
        ];
        if (str_contains($url, '/api/')) {
            $headers['Accept'] = 'application/json';
            $headers['Content-Type'] = 'application/json';
            $headers['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0 Safari/537.36';
            $headers['X-Requested-With'] = 'XMLHttpRequest';
            $headers['Origin'] = 'https://tienda.calimax.com.mx';
        }
        return ['headers' => $headers];
    }

    /**
     * Extrae productos: __NEXT_DATA__, __STATE__ (VTEX) o fallback regex/HTML.
     * Incluye categoria_origen cuando se puede inferir de la sección.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, categoria_origen?: string}>
     */
    protected function extraerProductosDeRespuesta(string $body, string $urlPagina): array
    {
        $productos = [];

        if (preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.+?)<\/script>/s', $body, $m)) {
            $json = json_decode(trim($m[1]), true);
            if (is_array($json)) {
                $productos = $this->mapearDesdeNextData($json);
            }
        }

        if (empty($productos)) {
            $stateStr = $this->extraerStateConBalanceo($body);
            if ($stateStr !== null) {
                $productos = $this->mapearDesdeVtexState($stateStr);
            }
        }

        if (empty($productos)) {
            $productos = $this->extraerDesdeJsonLd($body);
        }
        if (empty($productos)) {
            $productos = $this->extraerDesdeDomCss($body);
        }
        if (empty($productos)) {
            $productos = $this->extraerDesdeHtmlEnlaces($body, $urlPagina);
        }

        return array_slice($productos, 0, self::MAX_PRODUCTOS);
    }

    /**
     * Selector de emergencia: extrae name, price y link con DOM + XPath (clases VTEX / texto con $).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, categoria_origen?: string}>
     */
    protected function extraerDesdeDomCss(string $body): array
    {
        $productos = [];
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        @$dom->loadHTML($body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $contenedores = $xpath->query("//*[contains(@class,'vtex-product-summary') or contains(@class,'product-summary') or contains(@class,'vtex-product-list')]");
        if ($contenedores->length === 0) {
            $contenedores = $xpath->query("//a[contains(@href,'/p') and (contains(@href,'calimax') or contains(@href,'-') and string-length(@href)>20)]");
        }

        $base = self::URL_BASE;
        $vistos = [];
        foreach ($contenedores as $node) {
            $linkNode = $node->nodeName === 'a' ? $node : $xpath->query('.//a[contains(@href,"/p")]', $node)->item(0);
            $href = $linkNode ? trim($linkNode->getAttribute('href') ?? '') : '';
            if ($href === '') {
                continue;
            }
            if (! str_starts_with($href, 'http')) {
                $href = $base . '/' . ltrim($href, '/');
            }
            $hrefLower = strtolower($href);
            foreach (self::DOMINIOS_PORTAL_VTEX as $dominio) {
                if (str_contains($hrefLower, $dominio)) {
                    $href = null;
                    break;
                }
            }
            if ($href === null || $href === '') {
                continue;
            }
            if (preg_match('/\/(\d+)\/p/', $href, $m)) {
                $sku = $m[1];
            } else {
                $sku = substr(md5($href), 0, 12);
            }
            if (isset($vistos[$sku])) {
                continue;
            }
            $vistos[$sku] = true;

            $nombre = '';
            $nameNode = $xpath->query(".//*[contains(@class,'product-name') or contains(@class,'productName') or contains(@class,'name')]", $node)->item(0);
            if ($nameNode) {
                $nombre = trim($nameNode->textContent ?? '');
            }
            if ($nombre === '' && $linkNode) {
                $nombre = trim($linkNode->textContent ?? '');
            }
            $nombre = preg_replace('/\s*\$\d[\d.,]*\s*\$?\d*[\d.,]*.*$/u', '', $nombre);
            $nombre = trim(preg_replace('/^-\d+%\s*/', '', $nombre)) ?: 'Producto Calimax';

            $precioOriginal = 0.0;
            $precioOferta = null;
            $scope = $node->nodeName === 'a' ? $node->parentNode : $node;
            if ($scope) {
                $texto = $scope->textContent ?? '';
                if (preg_match('/\$([\d.,]+)\s*\$([\d.,]+)/', $texto, $pm)) {
                    $precioOriginal = (float) str_replace(',', '', $pm[1]);
                    $precioOferta = (float) str_replace(',', '', $pm[2]);
                } elseif (preg_match('/\$([\d.,]+)/', $texto, $pm)) {
                    $precioOriginal = (float) str_replace(',', '', $pm[1]);
                    $precioOferta = $precioOriginal;
                }
            }
            if ($precioOriginal <= 0) {
                continue;
            }

            $productos[] = [
                'sku_tienda' => 'CAL-' . $sku,
                'nombre' => $nombre,
                'precio_original' => round($precioOriginal, 2),
                'precio_oferta' => $precioOferta !== null ? round($precioOferta, 2) : null,
                'imagen_url' => $this->construirUrlImagenCalimax((string) $sku),
                'url_original' => $href,
                'categoria_origen' => 'Ofertas',
            ];
        }

        return $productos;
    }

    /**
     * Extrae __STATE__ con balanceo de llaves (evita fallo del regex en JSON muy grande).
     */
    protected function extraerStateConBalanceo(string $body): ?string
    {
        $pos = strpos($body, '__STATE__');
        if ($pos === false) {
            return null;
        }
        $pos = strpos($body, '=', $pos) + 1;
        $pos = strpos($body, '{', $pos);
        if ($pos === false) {
            return null;
        }
        $depth = 0;
        $start = $pos;
        $len = strlen($body);
        for ($i = $pos; $i < $len; $i++) {
            $c = $body[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($body, $start, $i - $start + 1);
                }
            } elseif ($c === '"' && $i > 0 && $body[$i - 1] !== '\\') {
                while ($i + 1 < $len) {
                    $i++;
                    if ($body[$i] === '\\') {
                        $i++;
                    } elseif ($body[$i] === '"') {
                        break;
                    }
                }
            }
            if ($i - $start > 8000000) {
                break;
            }
        }
        return null;
    }

    /**
     * Fallback: extrae productos de scripts JSON-LD (VTEX/schema.org Product o ItemList).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, categoria_origen?: string}>
     */
    protected function extraerDesdeJsonLd(string $body): array
    {
        $productos = [];
        if (! preg_match_all('/<script[^>]*type\s*=\s*["\']application\/ld\+json["\'][^>]*>(.+?)<\/script>/si', $body, $bloques)) {
            return [];
        }
        foreach ($bloques[1] as $jsonStr) {
            $jsonStr = trim($jsonStr);
            $data = json_decode($jsonStr, true);
            if (! is_array($data)) {
                continue;
            }
            $items = [];
            if (isset($data['@type']) && $data['@type'] === 'ItemList' && isset($data['itemListElement']) && is_array($data['itemListElement'])) {
                $items = $data['itemListElement'];
            } elseif (isset($data['@type']) && (str_contains((string) $data['@type'], 'Product') || $data['@type'] === 'Product')) {
                $items = [$data];
            } elseif (isset($data['@graph']) && is_array($data['@graph'])) {
                foreach ($data['@graph'] as $node) {
                    if (is_array($node) && isset($node['@type']) && (str_contains((string) $node['@type'], 'Product') || $node['@type'] === 'ItemList')) {
                        if ($node['@type'] === 'ItemList' && isset($node['itemListElement'])) {
                            $items = array_merge($items, is_array($node['itemListElement']) ? $node['itemListElement'] : []);
                        } else {
                            $items[] = $node;
                        }
                    }
                }
            }
            foreach (array_slice($items, 0, self::MAX_PRODUCTOS) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $m = $this->normalizarItemJsonLd($item);
                if ($m !== null) {
                    $productos[] = $m;
                }
            }
        }
        return $productos;
    }

    /**
     * @param  array<string, mixed>  $item  Nodo Product de schema.org
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, categoria_origen?: string}|null
     */
    protected function normalizarItemJsonLd(array $item): ?array
    {
        $nombre = (string) ($item['name'] ?? $item['title'] ?? '');
        if ($nombre === '') {
            return null;
        }
        $sku = (string) ($item['sku'] ?? $item['productID'] ?? $item['@id'] ?? '');
        $skuTienda = 'CAL-' . ($sku ?: substr(md5($nombre), 0, 12));

        $precioOriginal = 0.0;
        $precioOferta = null;
        $offers = $item['offers'] ?? null;
        if (is_array($offers)) {
            if (isset($offers['lowPrice']) && isset($offers['highPrice'])) {
                $precioOriginal = (float) $offers['highPrice'];
                $precioOferta = (float) $offers['lowPrice'];
            } elseif (isset($offers[0]) && is_array($offers[0])) {
                $first = $offers[0];
                $precioOferta = (float) ($first['price'] ?? $first['lowPrice'] ?? 0);
                $precioOriginal = (float) ($first['highPrice'] ?? $first['price'] ?? $precioOferta);
            } else {
                $price = (float) ($offers['price'] ?? $offers['lowPrice'] ?? 0);
                if ($price > 0) {
                    $precioOferta = $price;
                    $precioOriginal = (float) ($offers['highPrice'] ?? $offers['listPrice'] ?? $price);
                }
            }
        }
        if ($precioOriginal <= 0 && $precioOferta !== null) {
            $precioOriginal = $precioOferta;
        }
        if ($precioOriginal <= 0) {
            return null;
        }

        $imagenUrl = $item['image'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? (is_string($imagenUrl[0] ?? null) ? $imagenUrl[0] : null);
        }
        $urlOriginal = $this->construirUrlPublicaCalimax($item);

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Calimax',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta !== null ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal,
            'categoria_origen' => $item['category'] ?? $item['department'] ?? 'Ofertas',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, categoria_origen?: string}>
     */
    protected function mapearDesdeNextData(array $data): array
    {
        $items = $data['props']['pageProps']['products'] ?? $data['props']['pageProps']['items'] ?? $data['props']['pageProps']['searchResult']['products'] ?? [];
        if (! is_array($items)) {
            return [];
        }
        $productos = [];
        foreach (array_slice($items, 0, self::MAX_PRODUCTOS) as $item) {
            $m = $this->normalizarItem($item);
            if ($m !== null) {
                $productos[] = $m;
            }
        }
        return $productos;
    }

    /**
     * Parsea __STATE__ de VTEX. Filtra por claves "Product" (Product:xxx). Soporta listas search.products y Product:sp-XXX.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, categoria_origen?: string}>
     */
    protected function mapearDesdeVtexState(string $jsonStr): array
    {
        $data = json_decode($jsonStr, true);
        if (! is_array($data)) {
            return [];
        }
        $resueltos = [];

        // Prioridad: todas las claves que empiezan por "Product:" (página dinámica VTEX).
        foreach ($data as $key => $valor) {
            if (! is_string($key) || ! is_array($valor)) {
                continue;
            }
            if (str_starts_with($key, 'Product:') && substr_count($key, '.') === 0 && (isset($valor['productName']) || isset($valor['name']) || isset($valor['title']))) {
                $resueltos[] = $valor;
            }
        }

        if ($resueltos === []) {
            $items = $data['search']['products'] ?? $data['search']['productSummaries'] ?? $data['productList'] ?? $data['products'] ?? [];
            if (is_array($items)) {
                foreach (array_slice($items, 0, 80) as $entrada) {
                    if (is_string($entrada)) {
                        $producto = $data['Product:' . $entrada] ?? $data['ProductSummary:' . $entrada] ?? null;
                        if (is_array($producto)) {
                            $resueltos[] = $producto;
                        }
                    } elseif (is_array($entrada)) {
                        $resueltos[] = $entrada;
                    }
                }
            }
        }
        if ($resueltos === []) {
            foreach ($data as $valor) {
                if (! is_array($valor)) {
                    continue;
                }
                $lista = $valor['products'] ?? $valor['items'] ?? null;
                if (is_array($lista) && count($lista) > 0) {
                    $primero = $lista[0] ?? [];
                    if (is_array($primero) && (isset($primero['name']) || isset($primero['productName']) || isset($primero['title']))) {
                        $resueltos = $lista;
                        break;
                    }
                }
            }
        }

        $productos = [];
        foreach (array_slice($resueltos, 0, self::MAX_PRODUCTOS) as $item) {
            $m = $this->normalizarItemVtex($item, $data);
            if ($m !== null) {
                $productos[] = $m;
            }
        }

        if ($productos === [] && $data !== []) {
            $primerasLlaves = array_slice(array_keys($data), 0, 10);
            Log::warning(static::class . ': __STATE__ sin productos. Primeras 10 llaves del JSON para diagnóstico.', [
                'llaves' => $primerasLlaves,
            ]);
        }

        return $productos;
    }

    /**
     * Fallback: extrae desde enlaces tipo /nombre--------SKU/p y texto con precios $XX.XX$YY.YY o $XX.XX.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, categoria_origen?: string}>
     */
    protected function extraerDesdeHtmlEnlaces(string $body, string $urlPagina): array
    {
        $productos = [];
        $base = self::URL_BASE;

        // Enlaces a ficha de producto: .../--------823847/p o .../producto-823847/p
        if (! preg_match_all('/href\s*=\s*["\'](https?:\/\/[^"\']*calimax\.com\.mx\/[^"\']*\/(\d+)\/p)["\']/i', $body, $urlMatches, PREG_SET_ORDER)) {
            preg_match_all('/href\s*=\s*["\']([^"\']*\/(\d+)\/p)["\']/i', $body, $urlMatches, PREG_SET_ORDER);
        }

        foreach ($urlMatches as $match) {
            $urlCompleta = $match[1];
            if (! str_starts_with($urlCompleta, 'http')) {
                $urlCompleta = rtrim($base, '/') . '/' . ltrim($urlCompleta, '/');
            }
            $sku = $match[2];

            // Buscar en un fragmento alrededor del enlace: texto con $precio o $original$oferta
            $pos = strpos($body, $urlCompleta);
            if ($pos === false) {
                $pos = strpos($body, $match[1]);
            }
            $fragmento = $pos !== false ? substr($body, max(0, $pos - 600), 1200) : '';

            $nombre = $this->extraerNombreDesdeFragmento($fragmento);
            $precios = $this->extraerPreciosDesdeFragmento($fragmento);

            $precioOriginal = $precios['original'] ?? 0.0;
            $precioOferta = $precios['oferta'] ?? null;
            if ($precioOferta === null && $precioOriginal > 0) {
                $precioOferta = $precioOriginal;
            }
            if ($precioOriginal <= 0 && $precioOferta !== null) {
                $precioOriginal = $precioOferta;
            }
            if ($precioOriginal <= 0) {
                continue;
            }
            if ($precioOferta !== null && $precioOferta >= $precioOriginal) {
                $precioOferta = null;
            }

            $productos[] = [
                'sku_tienda' => 'CAL-' . $sku,
                'nombre' => $nombre ?: 'Producto Calimax',
                'precio_original' => round($precioOriginal, 2),
                'precio_oferta' => $precioOferta !== null ? round($precioOferta, 2) : null,
                'imagen_url' => $this->construirUrlImagenCalimax($sku),
                'url_original' => $urlCompleta,
                'categoria_origen' => 'Ofertas',
            ];
        }

        return $productos;
    }

    protected function extraerNombreDesdeFragmento(string $fragmento): string
    {
        // Entre > y $ o "Hasta" o primer número de precio: texto del enlace
        if (preg_match('/>([^<$]+?)(?:\s*\$[\d.,]+\s*\$?[\d.,]*|\s*Hasta)/u', $fragmento, $m)) {
            return trim(preg_replace('/^-\d+%\s*/', '', $m[1]));
        }
        if (preg_match('/>([^<]{5,120}?)</', $fragmento, $m)) {
            $t = trim($m[1]);
            $t = preg_replace('/\s*\$[\d.,]+\s*\$?[\d.,]*.*$/u', '', $t);
            $t = preg_replace('/^-\d+%\s*/', '', $t);
            return trim($t);
        }
        return '';
    }

    /**
     * @return array{original: float, oferta: float|null}
     */
    protected function extraerPreciosDesdeFragmento(string $fragmento): array
    {
        $result = ['original' => 0.0, 'oferta' => null];
        // Dos precios: $82.00$50.00
        if (preg_match('/\$([\d.,]+)\s*\$([\d.,]+)/', $fragmento, $m)) {
            $result['original'] = (float) str_replace(',', '', $m[1]);
            $result['oferta'] = (float) str_replace(',', '', $m[2]);
            return $result;
        }
        // Un precio: $59.90 (evitar capturar "1" de "1x" o "2x" — exigir decimal o al menos 2 dígitos)
        if (preg_match_all('/\$([\d.,]+)/', $fragmento, $m)) {
            foreach ($m[1] as $candidato) {
                $v = (float) str_replace(',', '', $candidato);
                if ($v >= 2 || str_contains($candidato, '.') || str_contains($candidato, ',')) {
                    $result['original'] = $v;
                    return $result;
                }
            }
        }
        return $result;
    }

    /**
     * Extrae precio desde un nodo de __STATE__ que puede ser número o referencia a objeto con highPrice/lowPrice.
     *
     * @param  array<string, mixed>  $priceData  Nodo priceRange del producto
     * @param  array<string, mixed>  $state  __STATE__ completo
     * @param  bool  $preferHigh  Si true, usar highPrice (precio lista); si false, lowPrice (oferta).
     */
    protected function extraerPrecioDesdeState(array $priceData, string $key, array $state, bool $preferHigh = false): float
    {
        $val = $priceData[$key] ?? $priceData['ListPrice'] ?? $priceData['SellingPrice'] ?? null;
        if (is_numeric($val)) {
            return (float) $val;
        }
        if (is_array($val) && isset($val['id'])) {
            $refId = (string) $val['id'];
            $refKey = ltrim($refId, '$');
            $resuelto = $state[$refKey] ?? $state[$refId] ?? null;
            if (is_array($resuelto)) {
                $p = $preferHigh
                    ? (float) ($resuelto['highPrice'] ?? $resuelto['lowPrice'] ?? $resuelto['price'] ?? 0)
                    : (float) ($resuelto['lowPrice'] ?? $resuelto['highPrice'] ?? $resuelto['price'] ?? 0);
                if ($p > 0) {
                    return $p;
                }
            }
        }
        return 0.0;
    }

    protected function construirUrlImagenCalimax(string $sku): ?string
    {
        if ($sku === '') {
            return null;
        }
        return self::IMAGEN_BASE . '/arquivos/ids/' . $sku . '-1.jpg';
    }

    /** Dominios de VTEX que no deben usarse como URL de producto (redirigen al login de administración). */
    private const DOMINIOS_PORTAL_VTEX = ['myvtex.com', 'vtexcommercestable.com.br', 'portal.'];

    /**
     * Construye la URL pública del producto. SOLO se usa linkText; el campo link de la API
     * suele apuntar al portal de administración VTEX y no debe usarse nunca.
     * Estructura: https://tienda.calimax.com.mx/{linkText}/p
     *
     * @param  array<string, mixed>  $item  Ítem de la API VTEX (debe contener linkText)
     * @return string|null  URL pública o null si no hay linkText.
     */
    protected function construirUrlPublicaCalimax(array $item): ?string
    {
        $base = 'https://tienda.calimax.com.mx';
        $linkText = trim((string) ($item['linkText'] ?? ''));
        if ($linkText === '') {
            return null;
        }
        $slug = trim($linkText, '/');
        $slug = preg_replace('#/+#', '/', $slug);
        if ($slug === '') {
            return null;
        }
        return $base . '/' . $slug . '/p';
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, categoria_origen?: string}|null
     */
    protected function normalizarItem(array $item): ?array
    {
        $sku = (string) ($item['sku'] ?? $item['productId'] ?? $item['id'] ?? $item['itemId'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['productName'] ?? $item['title'] ?? '');
        if ($sku === '' && $nombre === '') {
            return null;
        }
        $skuTienda = 'CAL-' . ($sku ?: substr(md5($nombre), 0, 12));
        $precioOriginal = (float) ($item['listPrice'] ?? $item['regularPrice'] ?? $item['originalPrice'] ?? 0);
        $precioOfertaRaw = $item['salePrice'] ?? $item['price'] ?? $item['offerPrice'] ?? null;
        $precioOferta = $precioOfertaRaw !== null ? (float) $precioOfertaRaw : null;
        if ($precioOriginal <= 0 && $precioOferta !== null) {
            $precioOriginal = $precioOferta;
        }
        if ($precioOferta !== null && $precioOferta >= $precioOriginal) {
            $precioOferta = null;
        }
        $imagenUrl = $item['image'] ?? $item['imageUrl'] ?? $item['thumbnail'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? null;
        }
        if (is_string($imagenUrl) && $imagenUrl !== '' && ! str_starts_with($imagenUrl, 'http')) {
            $imagenUrl = rtrim(self::IMAGEN_BASE, '/') . '/' . ltrim($imagenUrl, '/');
        }
        $urlOriginal = $this->construirUrlPublicaCalimax($item);
        $categoria = $item['category'] ?? $item['department'] ?? null;

        $out = [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Calimax',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta !== null ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal,
        ];
        if ($categoria !== null && $categoria !== '') {
            $out['categoria_origen'] = is_string($categoria) ? $categoria : (string) $categoria;
        }
        return $out;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $state
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, categoria_origen?: string}|null
     */
    protected function normalizarItemVtex(array $item, array $state = []): ?array
    {
        $sku = (string) ($item['productReference'] ?? $item['sku'] ?? $item['productId'] ?? $item['id'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['productName'] ?? $item['title'] ?? '');
        if ($sku === '' && $nombre === '') {
            return null;
        }

        $precioOriginal = 0.0;
        $precioOferta = 0.0;
        $priceRangeRef = $item['priceRange'] ?? null;
        if (is_array($priceRangeRef) && isset($priceRangeRef['id'])) {
            $refId = (string) ($priceRangeRef['id'] ?? '');
            $refKey = ltrim($refId, '$');
            $priceData = $state[$refKey] ?? $state[$refId] ?? null;
            if (is_array($priceData)) {
                $precioOferta = $this->extraerPrecioDesdeState($priceData, 'sellingPrice', $state);
                $precioOriginal = $this->extraerPrecioDesdeState($priceData, 'listPrice', $state);
                if ($precioOriginal <= 0) {
                    $precioOriginal = $this->extraerPrecioDesdeState($priceData, 'listPrice', $state, true);
                    if ($precioOriginal <= 0) {
                        $precioOriginal = $precioOferta;
                    }
                }
                if ($precioOferta <= 0 && $precioOriginal > 0) {
                    $precioOferta = $precioOriginal;
                }
            }
        }
        if ($precioOferta <= 0 || $precioOriginal <= 0) {
            $items = $item['items'] ?? [];
            $primerItem = is_array($items) ? ($items[0] ?? []) : [];
            $sellers = $primerItem['sellers'] ?? [];
            $primerSeller = is_array($sellers) ? ($sellers[0] ?? []) : [];
            $offer = $primerSeller['commertialOffer'] ?? $primerSeller['commercialOffer'] ?? [];
            if (is_array($offer)) {
                $precioOferta = (float) ($offer['Price'] ?? $offer['price'] ?? 0);
                $precioOriginal = (float) ($offer['ListPrice'] ?? $offer['listPrice'] ?? $precioOferta);
                if ($precioOriginal <= 0) {
                    $precioOriginal = $precioOferta;
                }
            }
        }
        if ($precioOferta <= 0 && $precioOriginal <= 0) {
            $precioOferta = (float) ($item['salePrice'] ?? $item['price'] ?? 0);
            $precioOriginal = (float) ($item['listPrice'] ?? $item['regularPrice'] ?? $precioOferta);
            if ($precioOriginal <= 0) {
                $precioOriginal = $precioOferta;
            }
        }
        if ($precioOferta <= 0 && $precioOriginal <= 0) {
            return null;
        }
        if ($precioOriginal === 1.0 && $precioOferta === 1.0) {
            return null;
        }

        $skuTienda = 'CAL-' . ($sku ?: substr(md5($nombre), 0, 12));
        $imagenUrl = $item['image'] ?? $item['imageUrl'] ?? $item['images'][0] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? null;
        }
        if (is_string($imagenUrl) && $imagenUrl !== '' && ! str_starts_with($imagenUrl, 'http')) {
            $imagenUrl = rtrim(self::IMAGEN_BASE, '/') . '/' . ltrim($imagenUrl, '/');
        }
        if (($imagenUrl === null || $imagenUrl === '') && $sku !== '') {
            $imagenUrl = $this->construirUrlImagenCalimax($sku);
        }
        $urlOriginal = $this->construirUrlPublicaCalimax($item);

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Calimax',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta < $precioOriginal ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal,
            'categoria_origen' => $item['category'] ?? $item['department'] ?? 'Ofertas',
        ];
    }

    /**
     * Recolecta ofertas vía API VTEX (estrategia principal; el HTML de especiales depende de JS).
     * API: productName, items[0].sellers[0].commertialOffer.Price, items[0].images[0].imageUrl.
     */
    public function recolectarDatos(): array
    {
        $this->peticionesRealizadas = 0;

        $productos = $this->recolectarDatosDesdeApiVtex();
        if (! empty($productos)) {
            Log::info(static::class . ': productos obtenidos vía API VTEX', ['total' => count($productos)]);
            return array_slice($productos, 0, self::MAX_PRODUCTOS);
        }

        $url = rtrim($this->getUrlBase(), '/') . '/' . ltrim($this->getRutaOfertas(), '/');
        $resultado = $this->realizarPeticion($url);
        if ($resultado === null) {
            Log::info(static::class . ': sin respuesta', ['url' => $url]);
            return [];
        }

        $status = $resultado['status'];
        $body = $resultado['body'];
        $productos = $this->extraerProductosDeRespuesta($body, $url);

        if (empty($productos)) {
            Log::warning(static::class . ': no se extrajeron productos. Status y HTML guardados para diagnóstico.', [
                'url' => $url,
                'status' => $status,
                'interpretacion' => $status === 403 ? 'posible bloqueo (403)' : ($status !== 200 ? 'respuesta no OK' : 'cambio de estructura HTML/JSON'),
            ]);
            $dir = storage_path('logs');
            if (is_dir($dir)) {
                @file_put_contents($dir . DIRECTORY_SEPARATOR . 'debug_calimax.html', $body);
            }
        }

        return $productos;
    }

    /**
     * Obtiene productos desde la API de catálogo VTEX (JSON puro). VTEX IO no sirve para rastreo HTML.
     * URL con _from/_to=49; si cluster 150 no devuelve nada, se intenta sin fq.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, categoria_origen?: string}>
     */
    protected function recolectarDatosDesdeApiVtex(): array
    {
        $base = rtrim($this->getUrlBase(), '/');
        $productos = [];

        $urlConFq = $base . self::API_SEARCH . '?fq=productClusterIds:' . self::CLUSTER_ESPECIALES . '&_from=0&_to=49';
        $resultado = $this->realizarPeticion($urlConFq);

        if ($resultado !== null && ($resultado['status'] === 401 || $resultado['status'] === 403)) {
            Log::error('API Bloqueada', ['cuerpo' => $resultado['body'], 'url' => $urlConFq, 'status' => $resultado['status']]);
        }

        if ($resultado !== null && $resultado['status'] === 200) {
            $data = json_decode($resultado['body'], true);
            if (! is_array($data) && $resultado['body'] !== '') {
                Log::warning('CalimaxMotor: respuesta API no es JSON (posible Captcha).', [
                    'inicio_respuesta' => mb_substr($resultado['body'], 0, 500),
                    'url' => $urlConFq,
                ]);
            }
            if (is_array($data)) {
                foreach ($data as $item) {
                    $m = $this->mapearItemApiVtex($item);
                    if ($m !== null) {
                        $productos[] = $m;
                    }
                }
            }
        }

        if (empty($productos)) {
            $urlSinFq = $base . self::API_SEARCH . '?_from=0&_to=49';
            $resultado = $this->realizarPeticion($urlSinFq);
            if ($resultado !== null && ($resultado['status'] === 401 || $resultado['status'] === 403)) {
                Log::error('API Bloqueada', ['cuerpo' => $resultado['body'], 'url' => $urlSinFq, 'status' => $resultado['status']]);
            }
            if ($resultado !== null && $resultado['status'] === 200) {
                $data = json_decode($resultado['body'], true);
                if (! is_array($data) && $resultado['body'] !== '') {
                    Log::warning('CalimaxMotor: respuesta API no es JSON (posible Captcha).', [
                        'inicio_respuesta' => mb_substr($resultado['body'], 0, 500),
                        'url' => $urlSinFq,
                    ]);
                }
                if (is_array($data)) {
                    foreach (array_slice($data, 0, self::MAX_PRODUCTOS) as $item) {
                        $m = $this->mapearItemApiVtex($item);
                        if ($m !== null) {
                            $productos[] = $m;
                        }
                    }
                }
            }
        }

        return $productos;
    }

    /**
     * Mapea un ítem de la API VTEX search al formato interno.
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, categoria_origen?: string}|null
     */
    protected function mapearItemApiVtex(array $item): ?array
    {
        $nombre = (string) ($item['productName'] ?? $item['productTitle'] ?? $item['name'] ?? '');
        if ($nombre === '') {
            return null;
        }
        $items = $item['items'] ?? [];
        $primerItem = is_array($items) ? ($items[0] ?? []) : [];
        $itemId = (string) ($primerItem['itemId'] ?? $primerItem['referenceId'] ?? $item['productId'] ?? $item['productReference'] ?? '');
        $skuTienda = 'CAL-' . ($itemId ?: substr(md5($nombre), 0, 12));

        $precioOriginal = 0.0;
        $precioOferta = null;
        $sellers = $primerItem['sellers'] ?? [];
        $primerSeller = is_array($sellers) ? ($sellers[0] ?? []) : [];
        $offer = $primerSeller['commertialOffer'] ?? $primerSeller['commercialOffer'] ?? [];
        if (is_array($offer)) {
            $precioOferta = (float) ($offer['Price'] ?? $offer['price'] ?? 0);
            $precioOriginal = (float) ($offer['ListPrice'] ?? $offer['listPrice'] ?? $precioOferta);
            if ($precioOriginal <= 0) {
                $precioOriginal = $precioOferta;
            }
        }
        if ($precioOferta <= 0 && $precioOriginal <= 0) {
            return null;
        }
        if ($precioOriginal === 1.0 && $precioOferta <= 1.0) {
            return null;
        }
        if ($precioOferta !== null && $precioOferta >= $precioOriginal) {
            $precioOferta = null;
        }

        $link = $this->construirUrlPublicaCalimax($item);

        $imagenUrl = null;
        if (isset($primerItem['images']) && is_array($primerItem['images']) && isset($primerItem['images'][0])) {
            $img = $primerItem['images'][0];
            $imagenUrl = $img['imageUrl'] ?? $img['imageTag'] ?? null;
        }
        $imagenUrl = $imagenUrl ?? $item['image'] ?? null;
        if (is_string($imagenUrl) && $imagenUrl !== '' && ! str_starts_with($imagenUrl, 'http')) {
            $imagenUrl = rtrim(self::IMAGEN_BASE, '/') . '/' . ltrim($imagenUrl, '/');
        }
        if (($imagenUrl === null || $imagenUrl === '') && $itemId !== '') {
            $imagenUrl = $this->construirUrlImagenCalimax($itemId);
        }
        if ($imagenUrl === null || $imagenUrl === '') {
            $ref = (string) ($primerItem['referenceId'] ?? $itemId);
            if ($ref !== '') {
                $imagenUrl = $this->construirUrlImagenCalimax($ref);
            }
        }

        $categoria = $item['categories'] ?? $item['category'] ?? 'Ofertas';
        if (is_array($categoria)) {
            $categoria = implode(' ', $categoria) ?: 'Ofertas';
        }

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Calimax',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta !== null ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $link ?: null,
            'categoria_origen' => (string) $categoria,
        ];
    }
}
