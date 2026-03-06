<?php

namespace App\Motores;

use App\Models\Configuracion;
use App\Support\HttpRastreador;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Amazon México (Gold Box).
 * - URL: amazon.com.mx/gp/goldbox.
 * - Proxy dedicado: config('services.proxy_url_amazon') o fallback a proxy general. Smartproxy: usar sesión session-AmazonMX_Pro01.
 * - Tag de afiliado: anadirTagAmazon() en todos los enlaces (?tag= env AMAZON_TAG, default micosmtics-20).
 * - Selectores: contenedor [data-testid="grid-desktop-item"], precio .a-price-whole, nombre h2.
 */
class AmazonMexicoMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.amazon.com.mx';

    /** Ofertas del día (Gold Box). */
    protected const RUTA_OFERTAS = 'gp/goldbox';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Cabeceras que simulan Chrome para evitar bloqueos.
     *
     * @return array<string, string>
     */
    protected function cabecerasChrome(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'es-MX,es;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Referer' => self::URL_BASE . '/',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'Sec-Ch-Ua' => '"Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Cache-Control' => 'max-age=0',
            'Connection' => 'keep-alive',
            'DNT' => '1',
        ];
    }

    /**
     * Recolecta datos desde Gold Box. Usa proxy de Amazon (sesión distinta a ML) y caché 10 min para ahorrar GB.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $url = rtrim(self::URL_BASE, '/') . '/' . ltrim(self::RUTA_OFERTAS, '/');
        $proxyAmazon = config('services.proxy_url_amazon') ?: Configuracion::getProxyUrl();

        $resultado = HttpRastreador::getCachedOrFetch($url, function () use ($url, $proxyAmazon): array {
            $request = Http::withHeaders($this->cabecerasChrome())
                ->timeout(15)
                ->connectTimeout(10)
                ->withOptions(['verify' => true]);
            $request = HttpRastreador::conProxySiTexto($request, $url, $proxyAmazon);
            $respuesta = $request->get($url);

            return ['body' => $respuesta->body(), 'status' => $respuesta->status()];
        }, HttpRastreador::CACHE_PROXY_TTL);

        if ($resultado['status'] < 200 || $resultado['status'] >= 300) {
            Log::info('AmazonMexicoMotor: respuesta no exitosa', ['url' => $url, 'status' => $resultado['status']]);
            return [];
        }

        $productos = $this->extraerProductosDeRespuesta($resultado['body'], $url);

        if (empty($productos) && app()->environment('local')) {
            Log::info('AmazonMexicoMotor: sin datos de extracción; usando producto de prueba para verificación.');
            return [$this->productoDePrueba()];
        }

        return array_slice($productos, 0, 50);
    }

    /**
     * Extrae productos: primero scripts JSON (estado de búsqueda, JSON-LD), luego DOM (.s-result-item / data-asin).
     * El precio se limpia de '$' y ',' antes de convertir a decimal.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerProductosDeRespuesta(string $body, string $urlPagina): array
    {
        // 1) Bloques de script JSON que Amazon suele usar (estado de búsqueda, ofertas)
        $productos = $this->extraerDesdeJsonEstado($body);
        if (! empty($productos)) {
            Log::info('AmazonMexicoMotor: productos extraídos desde JSON estado', ['cantidad' => count($productos)]);

            return array_slice($productos, 0, 50);
        }

        // 2) JSON-LD (Product)
        $productos = $this->extraerDesdeJsonLd($body);
        if (! empty($productos)) {
            Log::info('AmazonMexicoMotor: productos extraídos desde JSON-LD', ['cantidad' => count($productos)]);

            return array_slice($productos, 0, 50);
        }

        // 3) DOM: .s-result-item o elementos con data-asin, precio en .a-offscreen
        $productos = $this->extraerDesdeDom($body);
        if (! empty($productos)) {
            Log::info('AmazonMexicoMotor: productos extraídos desde DOM', ['cantidad' => count($productos)]);

            return array_slice($productos, 0, 50);
        }

        $this->registrarRespuestaParaDebug($body, $urlPagina);

        return [];
    }

    /**
     * Cuando la extracción falla, registra en el log qué clase de HTML se recibió para ajustar selectores.
     */
    protected function registrarRespuestaParaDebug(string $body, string $urlPagina): void
    {
        $longitud = strlen($body);
        $tieneNextData = str_contains($body, '__NEXT_DATA__');
        $tieneDataAsin = str_contains($body, 'data-asin');
        $titulo = '';
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $body, $m)) {
            $titulo = trim(strip_tags($m[1]));
        }
        $inicio = mb_substr($body, 0, 800);

        Log::warning('AmazonMexicoMotor: extracción fallida. Respuesta para ajustar selectores.', [
            'url' => $urlPagina,
            'longitud_body' => $longitud,
            'tiene___NEXT_DATA__' => $tieneNextData,
            'tiene_data_asin' => $tieneDataAsin,
            'titulo_pagina' => $titulo,
            'inicio_html' => $inicio,
        ]);
    }

    /**
     * Busca datos en bloques de script JSON (estado de búsqueda / ofertas que Amazon inyecta).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeJsonEstado(string $body): array
    {
        $productos = [];
        // Patrones típicos: P.setState(...), dataToReturn = {...}, "searchResult": {...}
        if (preg_match('/"searchResults":\s*\[(.+?)\]/s', $body, $m)) {
            $jsonStr = '[' . $m[1] . ']';
            $items = json_decode($jsonStr, true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $n = $this->normalizarItemDesdeEstado($item);
                    if ($n !== null) {
                        $productos[] = $n;
                    }
                }
            }
        }
        if (empty($productos) && preg_match('/"results":\s*\[(.+?)\]/s', $body, $m)) {
            $jsonStr = '[' . $m[1] . ']';
            $items = json_decode($jsonStr, true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $n = $this->normalizarItemDesdeEstado($item);
                    if ($n !== null) {
                        $productos[] = $n;
                    }
                }
            }
        }

        return $productos;
    }

    /**
     * Normaliza ítem extraído del estado JSON (asin, title, prices, image).
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItemDesdeEstado(array $item): ?array
    {
        $asin = (string) ($item['asin'] ?? $item['id'] ?? '');
        $nombre = (string) ($item['title'] ?? $item['rawTitle'] ?? $item['name'] ?? '');
        if ($asin === '') {
            return null;
        }
        $precioOriginal = isset($item['listPrice']) ? $this->precioADecimal($item['listPrice']) : null;
        $precioOferta = isset($item['price']) ? $this->precioADecimal($item['price']) : (isset($item['currentPrice']) ? $this->precioADecimal($item['currentPrice']) : null);
        if ($precioOriginal === null && $precioOferta !== null) {
            $precioOriginal = $precioOferta;
        }
        if ($precioOriginal === null) {
            $precioOriginal = 0.0;
        }
        $imagenUrl = $item['image'] ?? $item['thumbnail'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? null;
        }

        return [
            'sku_tienda' => 'AMZ-' . $asin,
            'nombre' => $nombre ?: 'Producto Amazon ' . $asin,
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta !== null ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $this->anadirTagAmazon(self::URL_BASE . '/dp/' . $asin),
        ];
    }

    /**
     * Convierte precio (string o número) a float; limpia '$', ',' y espacios.
     */
    protected function precioADecimal(mixed $valor): ?float
    {
        if (is_numeric($valor)) {
            return (float) $valor;
        }
        $texto = is_string($valor) ? $valor : (string) $valor;

        return $this->parsearPrecioAmazon($texto);
    }

    /**
     * Producto de prueba para verificar comando y logs cuando la extracción real no devuelve datos.
     *
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}
     */
    protected function productoDePrueba(): array
    {
        return [
            'sku_tienda' => 'AMZ-PRUEBA-LOCAL',
            'nombre' => 'Producto de prueba Amazon (local)',
            'precio_original' => 499.00,
            'precio_oferta' => 399.00,
            'imagen_url' => null,
            'url_original' => self::URL_BASE . '/dp/PRUEBA',
        ];
    }

    /**
     * Extrae productos desde script type="application/ld+json" (Product).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeJsonLd(string $body): array
    {
        $productos = [];
        if (! preg_match_all('/<script type="application\/ld\+json">\s*(\{.+\})\s*<\/script>/sU', $body, $bloques)) {
            return [];
        }
        foreach ($bloques[1] as $jsonStr) {
            $json = json_decode(trim($jsonStr), true);
            if (! is_array($json)) {
                continue;
            }
            $items = isset($json['@graph']) ? $json['@graph'] : [$json];
            foreach ($items as $nodo) {
                if (($nodo['@type'] ?? '') !== 'Product') {
                    continue;
                }
                $mapeado = $this->normalizarItemDesdeJsonLd($nodo);
                if ($mapeado !== null) {
                    $productos[] = $mapeado;
                }
            }
        }

        return $productos;
    }

    /**
     * Extracción por DOM: varios selectores porque Amazon cambia clases con frecuencia.
     * 1) .s-result-item + data-asin, 2) data-component-type="s-search-result", 3) cualquier data-asin,
     * 4) fallback: ASIN desde enlaces /dp/ o /gp/product/ y tarjeta desde ancestro del enlace.
     * Precio desde .a-offscreen o .a-price.
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

        // 0) Selector moderno Gold Box: grid-desktop-item (h2 título, .a-price-whole precio)
        $nodos = $xpath->query("//*[@data-testid='grid-desktop-item']");
        if ($nodos !== false && $nodos->length > 0) {
            $productos = $this->mapearGridItemsAProductos($xpath, $nodos);
        }

        // 1) Contenedores con data-asin (Amazon puede cambiar la clase, por eso probamos varias)
        if (empty($productos)) {
            $nodos = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' s-result-item ') and @data-asin and string-length(normalize-space(@data-asin)) > 0]");
            if ($nodos === false || $nodos->length === 0) {
                $nodos = $xpath->query("//*[@data-component-type='s-search-result' and @data-asin and string-length(normalize-space(@data-asin)) > 0]");
            }
            if ($nodos === false || $nodos->length === 0) {
                $nodos = $xpath->query("//*[@data-asin and string-length(normalize-space(@data-asin)) > 0 and not(ancestor::*[@data-asin])]");
            }
            if ($nodos !== false && $nodos->length > 0) {
                $productos = $this->mapearNodosAProductos($xpath, $nodos);
            }
        }

        // 2) Fallback sin data-asin: extraer ASIN desde enlaces /dp/ o /gp/product/ y usar ancestro como tarjeta
        if (empty($productos)) {
            $productos = $this->extraerDesdeEnlacesConAsin($xpath, $dom);
        }

        libxml_clear_errors();

        return $productos;
    }

    /**
     * Convierte nodos DOM (con data-asin) a array de productos.
     *
     * @param  \DOMNodeList<\DOMNode>  $nodos
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearNodosAProductos(DOMXPath $xpath, \DOMNodeList $nodos): array
    {
        $productos = [];
        $asinsVistos = [];
        foreach ($nodos as $nodo) {
            $asin = $nodo instanceof \DOMElement ? trim($nodo->getAttribute('data-asin')) : '';
            if ($asin === '' || strlen($asin) < 10 || isset($asinsVistos[$asin])) {
                continue;
            }

            $precio = $this->extraerPrecioDesdeNodo($xpath, $nodo);
            $enlace = $this->extraerEnlaceDesdeNodo($xpath, $nodo);
            $nombre = $this->extraerNombreDesdeNodo($xpath, $nodo);
            $imagenUrl = $this->extraerImagenDesdeNodo($xpath, $nodo);

            if ($precio === null && $nombre === '') {
                continue;
            }

            $asinsVistos[$asin] = true;
            $urlOriginal = $enlace !== '' ? $enlace : (self::URL_BASE . '/dp/' . $asin);
            if (str_starts_with($urlOriginal, '/')) {
                $urlOriginal = self::URL_BASE . $urlOriginal;
            }
            $urlOriginal = $this->anadirTagAmazon($urlOriginal);

            $precioFloat = $precio !== null ? (float) $precio : 0.0;

            $productos[] = [
                'sku_tienda' => 'AMZ-' . $asin,
                'nombre' => $nombre !== '' ? $nombre : 'Producto Amazon ' . $asin,
                'precio_original' => round($precioFloat, 2),
                'precio_oferta' => $precioFloat > 0 ? round($precioFloat, 2) : null,
                'imagen_url' => $imagenUrl !== '' ? $imagenUrl : null,
                'url_original' => $urlOriginal,
            ];
        }

        return $productos;
    }

    /**
     * Mapea nodos [data-testid="grid-desktop-item"] a productos (ASIN desde enlace, h2 título, .a-price-whole precio).
     *
     * @param  \DOMNodeList<\DOMNode>  $nodos
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearGridItemsAProductos(DOMXPath $xpath, \DOMNodeList $nodos): array
    {
        $productos = [];
        $asinsVistos = [];
        foreach ($nodos as $nodo) {
            $enlace = $this->extraerEnlaceDesdeNodo($xpath, $nodo);
            $asin = $enlace !== '' ? $this->extraerAsinDeHref($enlace) : '';
            if ($asin === '' || strlen($asin) < 10 || isset($asinsVistos[$asin])) {
                continue;
            }

            $precio = $this->extraerPrecioDesdeNodo($xpath, $nodo);
            $nombre = $this->extraerNombreDesdeNodo($xpath, $nodo);
            $imagenUrl = $this->extraerImagenDesdeNodo($xpath, $nodo);

            if ($precio === null && $nombre === '') {
                continue;
            }

            $asinsVistos[$asin] = true;
            $urlOriginal = $enlace !== '' ? $enlace : (self::URL_BASE . '/dp/' . $asin);
            if (str_starts_with($urlOriginal, '/')) {
                $urlOriginal = self::URL_BASE . $urlOriginal;
            }
            $urlOriginal = $this->anadirTagAmazon($urlOriginal);

            $precioFloat = $precio !== null ? (float) $precio : 0.0;

            $productos[] = [
                'sku_tienda' => 'AMZ-' . $asin,
                'nombre' => $nombre !== '' ? $nombre : 'Producto Amazon ' . $asin,
                'precio_original' => round($precioFloat, 2),
                'precio_oferta' => $precioFloat > 0 ? round($precioFloat, 2) : null,
                'imagen_url' => $imagenUrl !== '' ? $imagenUrl : null,
                'url_original' => $urlOriginal,
            ];
        }

        return $productos;
    }

    /**
     * Añade el tag de afiliado Amazon a la URL (config services.amazon_tag).
     */
    protected function anadirTagAmazon(string $url): string
    {
        $tag = Configuracion::getAmazonTag();
        if ($tag === null || $tag === '') {
            return $url;
        }
        $separador = str_contains($url, '?') ? '&' : '?';
        if (str_contains($url, 'tag=')) {
            return preg_replace('/tag=[^&]+/', 'tag=' . $tag, $url);
        }

        return $url . $separador . 'tag=' . $tag;
    }

    /**
     * Cuando no hay data-asin: busca enlaces a /dp/ o /gp/product/, extrae ASIN del href
     * y usa el ancestro del enlace (tarjeta) para precio/nombre/imagen.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeEnlacesConAsin(DOMXPath $xpath, DOMDocument $dom): array
    {
        $productos = [];
        $enlaces = $xpath->query("//a[contains(@href, '/dp/') or contains(@href, '/gp/product/')]");
        if ($enlaces === false || $enlaces->length === 0) {
            return [];
        }

        $asinsVistos = [];
        foreach ($enlaces as $link) {
            if (! $link instanceof \DOMElement) {
                continue;
            }
            $href = trim($link->getAttribute('href') ?? '');
            $asin = $this->extraerAsinDeHref($href);
            if ($asin === '' || strlen($asin) < 10 || isset($asinsVistos[$asin])) {
                continue;
            }

            $tarjeta = $this->obtenerTarjetaProductoDesdeEnlace($xpath, $link);
            if ($tarjeta === null) {
                continue;
            }

            $precio = $this->extraerPrecioDesdeNodo($xpath, $tarjeta);
            $nombre = $this->extraerNombreDesdeNodo($xpath, $tarjeta);
            if ($nombre === '' && $link->textContent !== null) {
                $nombre = trim($link->textContent);
            }
            $imagenUrl = $this->extraerImagenDesdeNodo($xpath, $tarjeta);

            if ($precio === null && $nombre === '') {
                continue;
            }

            $asinsVistos[$asin] = true;
            $urlOriginal = str_starts_with($href, 'http') ? $href : (self::URL_BASE . '/' . ltrim(explode('?', $href)[0], '/'));
            $urlOriginal = $this->anadirTagAmazon($urlOriginal);
            $precioFloat = $precio !== null ? (float) $precio : 0.0;

            $productos[] = [
                'sku_tienda' => 'AMZ-' . $asin,
                'nombre' => $nombre !== '' ? $nombre : 'Producto Amazon ' . $asin,
                'precio_original' => round($precioFloat, 2),
                'precio_oferta' => $precioFloat > 0 ? round($precioFloat, 2) : null,
                'imagen_url' => $imagenUrl !== '' ? $imagenUrl : null,
                'url_original' => $urlOriginal,
            ];
        }

        return $productos;
    }

    /**
     * Extrae ASIN del href (formato /dp/B08N5WRWNW o /gp/product/B08N5WRWNW).
     */
    protected function extraerAsinDeHref(string $href): string
    {
        if (preg_match('#/(?:dp|gp/product)/([A-Z0-9]{10})#i', $href, $m)) {
            return strtoupper($m[1]);
        }

        return '';
    }

    /**
     * Sube desde el enlace hasta un ancestro que contenga .a-offscreen o .a-price (tarjeta de producto).
     */
    protected function obtenerTarjetaProductoDesdeEnlace(DOMXPath $xpath, \DOMElement $enlace): ?\DOMNode
    {
        $nodo = $enlace->parentNode;
        $maxNiveles = 15;
        $nivel = 0;
        while ($nodo !== null && $nivel < $maxNiveles) {
            $spans = $xpath->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' a-offscreen ') or contains(concat(' ', normalize-space(@class), ' '), ' a-price ')]", $nodo);
            if ($spans !== false && $spans->length > 0) {
                return $nodo;
            }
            $nodo = $nodo->parentNode;
            $nivel++;
        }

        return $enlace->parentNode;
    }

    /**
     * Precio desde .a-offscreen (principal), .a-price-whole (grid moderno) o .a-price; fallback: span con $número.
     */
    protected function extraerPrecioDesdeNodo(DOMXPath $xpath, \DOMNode $nodo): ?float
    {
        $spans = $xpath->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' a-offscreen ')]", $nodo);
        if ($spans !== false && $spans->length > 0) {
            $texto = trim($spans->item(0)->textContent ?? '');
            if ($texto !== '') {
                $p = $this->parsearPrecioAmazon($texto);
                if ($p !== null) {
                    return $p;
                }
            }
        }
        $spans = $xpath->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' a-price-whole ')]", $nodo);
        if ($spans !== false && $spans->length > 0) {
            $texto = trim($spans->item(0)->textContent ?? '');
            if ($texto !== '') {
                $p = $this->parsearPrecioAmazon($texto);
                if ($p !== null) {
                    return $p;
                }
            }
        }
        $spans = $xpath->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' a-price ')]", $nodo);
        if ($spans !== false && $spans->length > 0) {
            $texto = trim($spans->item(0)->textContent ?? '');
            if ($texto !== '') {
                $p = $this->parsearPrecioAmazon($texto);
                if ($p !== null) {
                    return $p;
                }
            }
        }
        // Fallback: cualquier span cuyo texto parezca precio ($ 1,234.56 o similar)
        $todos = $xpath->query(".//span", $nodo);
        if ($todos !== false) {
            foreach ($todos as $span) {
                $texto = trim($span->textContent ?? '');
                if (preg_match('/\$[\s\d,.]+\d/', $texto) && strlen($texto) < 30) {
                    $p = $this->parsearPrecioAmazon($texto);
                    if ($p !== null) {
                        return $p;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Enlace al producto (a[href*="/dp/"] o a[href*="/gp/product/"]).
     */
    protected function extraerEnlaceDesdeNodo(DOMXPath $xpath, \DOMNode $nodo): string
    {
        $links = $xpath->query(".//a[contains(@href, '/dp/') or contains(@href, '/gp/product/')]", $nodo);
        if ($links === false || $links->length === 0) {
            return '';
        }
        $primero = $links->item(0);
        $href = ($primero instanceof \DOMElement) ? trim($primero->getAttribute('href') ?? '') : '';
        if ($href === '') {
            return '';
        }
        if (str_starts_with($href, 'http')) {
            return $href;
        }

        return self::URL_BASE . '/' . ltrim($href, '/');
    }

    /**
     * Nombre/título: cualquier h2 (grid-desktop-item), luego h2 con s-line-clamp/a-text-normal, luego enlace /dp/.
     */
    protected function extraerNombreDesdeNodo(DOMXPath $xpath, \DOMNode $nodo): string
    {
        $h2 = $xpath->query(".//h2", $nodo);
        if ($h2 !== false && $h2->length > 0) {
            $texto = trim($h2->item(0)->textContent ?? '');
            if ($texto !== '' && strlen($texto) > 3) {
                return $texto;
            }
        }
        $h2 = $xpath->query(".//h2[contains(concat(' ', normalize-space(@class), ' '), ' s-line-clamp ') or contains(concat(' ', normalize-space(@class), ' '), ' a-text-normal ')]", $nodo);
        if ($h2 !== false && $h2->length > 0) {
            $texto = trim($h2->item(0)->textContent ?? '');
            if ($texto !== '' && strlen($texto) > 3) {
                return $texto;
            }
        }
        $link = $xpath->query(".//a[contains(@href, '/dp/') or contains(@href, '/gp/product/')]", $nodo);
        if ($link !== false && $link->length > 0) {
            foreach ($link as $a) {
                $texto = trim($a->textContent ?? '');
                if ($texto !== '' && strlen($texto) > 3 && ! preg_match('/^\d+$/', $texto)) {
                    return $texto;
                }
            }
        }

        return '';
    }

    /**
     * URL de imagen: img con class que contenga s-image.
     */
    protected function extraerImagenDesdeNodo(DOMXPath $xpath, \DOMNode $nodo): string
    {
        $imgs = $xpath->query(".//img[contains(concat(' ', normalize-space(@class), ' '), ' s-image ')]", $nodo);
        if ($imgs === false || $imgs->length === 0) {
            return '';
        }
        $primero = $imgs->item(0);
        $src = ($primero instanceof \DOMElement) ? trim($primero->getAttribute('src') ?? '') : '';
        if ($src !== '' && str_starts_with($src, '//')) {
            $src = 'https:' . $src;
        }

        return $src;
    }

    /**
     * Limpia símbolos ($, €, etc.) y comas de miles antes de convertir a decimal.
     */
    protected function parsearPrecioAmazon(string $texto): ?float
    {
        $texto = preg_replace('/\s+/', '', $texto);
        $texto = str_replace(['$', '€', 'MX$', 'USD'], '', $texto);
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }
        // Formato 1,234.56 (coma miles) o 1.234,56 (punto miles, coma decimal)
        if (preg_match('/^[\d.,]+$/', $texto)) {
            $ultimaComa = strrpos($texto, ',');
            $ultimoPunto = strrpos($texto, '.');
            if ($ultimaComa !== false && $ultimoPunto !== false) {
                $decimales = $ultimaComa > $ultimoPunto ? ',' : '.';
            } else {
                $decimales = ($ultimaComa !== false && ($ultimoPunto === false || strlen($texto) - $ultimaComa === 3)) ? ',' : '.';
            }
            $texto = str_replace([',', '.'], '', $texto);
            if ($decimales === ',') {
                $texto = substr($texto, 0, -2) . '.' . substr($texto, -2);
            }
        }
        $valor = (float) preg_replace('/[^\d.-]/', '', str_replace(',', '.', $texto));

        return $valor > 0 ? $valor : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItemDesdeJsonLd(array $item): ?array
    {
        $sku = (string) ($item['sku'] ?? $item['productID'] ?? $item['@id'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['title'] ?? '');
        if ($sku === '' && $nombre === '') {
            return null;
        }
        $sku = $sku !== '' ? preg_replace('/^AMZ-/', '', $sku) : '';
        $skuTienda = 'AMZ-' . ($sku !== '' ? $sku : substr(md5($nombre), 0, 12));
        $precio = (float) ($item['offers']['price'] ?? $item['offers'][0]['price'] ?? $item['price'] ?? 0);
        $precioOriginal = (float) ($item['listPrice'] ?? $item['offers']['price'] ?? $precio);
        if ($precioOriginal <= 0) {
            $precioOriginal = $precio;
        }
        $imagenUrl = is_string($item['image'] ?? null) ? $item['image'] : ($item['image']['url'] ?? null);
        $urlOriginal = $item['url'] ?? $item['offers']['url'] ?? null;
        if (is_string($urlOriginal) && ! str_starts_with($urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim($urlOriginal, '/');
        }

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Amazon',
            'precio_original' => round($precioOriginal > 0 ? $precioOriginal : 0, 2),
            'precio_oferta' => $precio > 0 ? round($precio, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? $this->anadirTagAmazon((string) $urlOriginal) : null,
        ];
    }
}
