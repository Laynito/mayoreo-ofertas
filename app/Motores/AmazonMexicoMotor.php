<?php

namespace App\Motores;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Amazon México.
 * Extracción real vía DOM (selectores robustos: data-asin, .a-offscreen, enlaces /dp/).
 */
class AmazonMexicoMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.amazon.com.mx';

    /** Página de ofertas (Today's Deals). */
    protected const RUTA_OFERTAS = 'gp/deals';

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
        return 'amazon';
    }

    /**
     * Recolecta datos; si en local no hay resultados, devuelve un producto de prueba para verificar log/BD.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $productos = parent::recolectarDatos();
        if (empty($productos) && app()->environment('local')) {
            Log::info('AmazonMexicoMotor: sin datos de extracción; usando producto de prueba para verificación.');
            return [ $this->productoDePrueba() ];
        }

        return $productos;
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
            'url_original' => self::URL_BASE . '/dp/' . $asin,
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
     * Extracción por DOM: primero .s-result-item (clase de resultados de búsqueda), luego cualquier data-asin.
     * Precio desde .a-offscreen (se limpia $ y , antes de convertir a decimal).
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

        // Preferir contenedores .s-result-item (selector estable de Amazon para resultados)
        $nodos = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' s-result-item ') and @data-asin and string-length(normalize-space(@data-asin)) > 0]");
        if ($nodos === false || $nodos->length === 0) {
            // Fallback: cualquier elemento con data-asin no vacío
            $nodos = $xpath->query("//*[@data-asin and string-length(normalize-space(@data-asin)) > 0]");
        }
        if ($nodos === false || $nodos->length === 0) {
            return [];
        }

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

            // Solo incluir si tenemos al menos ASIN y (precio o nombre)
            if ($precio === null && $nombre === '') {
                continue;
            }

            $asinsVistos[$asin] = true;
            $urlOriginal = $enlace !== '' ? $enlace : (self::URL_BASE . '/dp/' . $asin);
            if (str_starts_with($urlOriginal, '/')) {
                $urlOriginal = self::URL_BASE . $urlOriginal;
            }

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
        libxml_clear_errors();

        return $productos;
    }

    /**
     * Precio desde .a-offscreen dentro del nodo (selector robusto Amazon).
     */
    protected function extraerPrecioDesdeNodo(DOMXPath $xpath, \DOMNode $nodo): ?float
    {
        $spans = $xpath->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' a-offscreen ')]", $nodo);
        if ($spans === false || $spans->length === 0) {
            return null;
        }
        $texto = trim($spans->item(0)->textContent ?? '');
        if ($texto === '') {
            return null;
        }

        return $this->parsearPrecioAmazon($texto);
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
     * Nombre/título: texto del enlace al producto o h2.
     */
    protected function extraerNombreDesdeNodo(DOMXPath $xpath, \DOMNode $nodo): string
    {
        $h2 = $xpath->query(".//h2[contains(concat(' ', normalize-space(@class), ' '), ' s-line-clamp ')]", $nodo);
        if ($h2 !== false && $h2->length > 0) {
            $texto = trim($h2->item(0)->textContent ?? '');
            if ($texto !== '') {
                return $texto;
            }
        }
        $link = $xpath->query(".//a[contains(@href, '/dp/')]", $nodo);
        if ($link !== false && $link->length > 0) {
            $texto = trim($link->item(0)->textContent ?? '');
            if ($texto !== '') {
                return $texto;
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
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
    }
}
