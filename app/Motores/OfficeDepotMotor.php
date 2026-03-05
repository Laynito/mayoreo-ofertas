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
            $productos = array_slice($productos, 0, 50);
        }

        if (empty($productos)) {
            Log::info('OfficeDepotMotor: no se extrajeron productos (posible bloqueo, captcha o cambio de estructura en la página).', ['url' => $urlPagina]);
            return [];
        }

        // No devolver productos sin precios válidos (evitar guardar $0 en BD y que no aparezcan en mayoreo.cloud)
        $validos = $this->filtrarProductosConPrecioValido($productos);
        if (count($validos) === 0) {
            Log::info('OfficeDepotMotor: se extrajeron productos pero ninguno tiene precio válido (todos filtrados).', [
                'url' => $urlPagina,
                'extraidos_sin_filtrar' => count($productos),
            ]);
        }
        return $validos;
    }

    /**
     * Excluye productos con precio_original 0 y sin oferta para no guardar registros sin información útil.
     *
     * @param  array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>  $productos
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function filtrarProductosConPrecioValido(array $productos): array
    {
        return array_values(array_filter($productos, function (array $p) {
            $tienePrecioOriginal = isset($p['precio_original']) && (float) $p['precio_original'] > 0;
            $tieneOferta = isset($p['precio_oferta']) && $p['precio_oferta'] !== null && (float) $p['precio_oferta'] > 0;
            return $tienePrecioOriginal || $tieneOferta;
        }));
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
        $items = $props['products'] ?? $props['productGrid'] ?? $props['data']['products'] ?? $props['initialState']['products'] ?? $props['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            if (! is_array($item)) {
                continue;
            }
            // Algunas tiendas envuelven el producto en .product o .data
            $item = $item['product'] ?? $item['data'] ?? $item;
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
        $precioOferta = $this->extraerPrecioDesdeItem($item, true);
        $precioOriginal = $this->extraerPrecioDesdeItem($item, false) ?? $precioOferta;
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

    /**
     * Extrae precio de un ítem buscando en campos planos y anidados (pricing, priceInfo, SAP/Hybris, etc.).
     *
     * @param  array<string, mixed>  $item
     */
    protected function extraerPrecioDesdeItem(array $item, bool $oferta): ?float
    {
        if ($oferta) {
            $candidatos = [
                $item['price'] ?? null,
                $item['salePrice'] ?? null,
                $item['currentPrice'] ?? null,
                $item['promoPrice'] ?? null,
                $item['pricing']['salePrice'] ?? null,
                $item['pricing']['price'] ?? null,
                $item['pricing']['currentPrice'] ?? null,
                $item['priceInfo']['salePrice'] ?? null,
                $item['priceInfo']['price'] ?? null,
                isset($item['prices'][0]) ? $item['prices'][0] : null,
                $item['formattedPrice'] ?? null,
                $item['priceFormatted'] ?? null,
                $item['salePriceFormatted'] ?? null,
                $item['price']['value'] ?? null,
                $item['price']['formattedValue'] ?? null,
                $item['variants'][0]['price']['value'] ?? null,
                $item['variants'][0]['price'] ?? null,
            ];
        } else {
            $candidatos = [
                $item['basePrice'] ?? null,
                $item['listPrice'] ?? null,
                $item['originalPrice'] ?? null,
                $item['regularPrice'] ?? null,
                $item['pricing']['listPrice'] ?? null,
                $item['pricing']['basePrice'] ?? null,
                $item['pricing']['originalPrice'] ?? null,
                $item['priceInfo']['listPrice'] ?? null,
                $item['priceInfo']['originalPrice'] ?? null,
                $item['listPriceFormatted'] ?? null,
                $item['originalPriceFormatted'] ?? null,
                $item['priceRange']['min']['value'] ?? null,
                $item['variants'][0]['listPrice']['value'] ?? null,
                $item['variants'][0]['listPrice'] ?? null,
            ];
        }
        foreach ($candidatos as $valor) {
            if ($valor === null) {
                continue;
            }
            if (is_array($valor) && isset($valor['value'])) {
                $valor = $valor['value'];
            }
            $num = $this->precioANumero($valor);
            if ($num !== null && $num > 0) {
                return $num;
            }
        }
        // Fallback: buscar en el ítem cualquier número que parezca precio (1 - 9999999)
        $encontrado = $this->buscarPrecioEnArray($item, $oferta);
        if ($encontrado !== null) {
            return $encontrado;
        }
        return null;
    }

    /**
     * Busca recursivamente un valor numérico que parezca precio (evita nombres con números).
     *
     * @param  array<string, mixed>  $arr
     * @param  bool  $preferirMenor  true = oferta (menor), false = original (mayor)
     */
    protected function buscarPrecioEnArray(array $arr, bool $preferirMenor, int $profundidad = 0): ?float
    {
        if ($profundidad > 4) {
            return null;
        }
        $candidatos = [];
        foreach ($arr as $k => $v) {
            if (is_numeric($v) && (float) $v >= 1 && (float) $v <= 9999999) {
                $candidatos[] = (float) $v;
            }
            if (is_string($v) && preg_match('/^\$?[\d,]+\.?\d*$/', trim($v))) {
                $n = $this->precioANumero($v);
                if ($n !== null && $n >= 1 && $n <= 9999999) {
                    $candidatos[] = $n;
                }
            }
            if (is_array($v) && isset($v['value']) && is_numeric($v['value'])) {
                $n = (float) $v['value'];
                if ($n >= 1 && $n <= 9999999) {
                    $candidatos[] = $n;
                }
            }
            if (is_array($v)) {
                $rec = $this->buscarPrecioEnArray($v, $preferirMenor, $profundidad + 1);
                if ($rec !== null) {
                    $candidatos[] = $rec;
                }
            }
        }
        if ($candidatos === []) {
            return null;
        }
        return $preferirMenor ? min($candidatos) : max($candidatos);
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
