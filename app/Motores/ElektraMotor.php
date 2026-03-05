<?php

namespace App\Motores;

use App\Support\HttpRastreador;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Elektra México (VTEX).
 * Mismo ADN que Coppel: cabeceras de navegador Chrome y gestión de sesión (cookie de código postal).
 * Proxy solo para texto (HTML/API); las imágenes se piden con IP local.
 * Orden: 1) API catalog_system/pub/products/search (ofertas/descuento), 2) HTML /ofertas + __STATE__, 3) ruta alternativa.
 * Respeta permite_descuento_adicional (aplicado en RastreoTiendaComando al encolar).
 */
class ElektraMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.elektra.mx';

    protected const RUTA_OFERTAS = 'ofertas';

    /** Ruta alternativa si /ofertas está bloqueada (ej. categoría telefonia/celulares). */
    protected const RUTA_ALTERNATIVA = 'telefonia/celulares';

    /** Endpoints VTEX para intentar ofertas (discount / price_promotion). */
    private const API_OFERTAS_CANDIDATOS = [
        '/api/catalog_system/pub/products/search?_from=0&_to=49&ft=ofertas',
        '/api/catalog_system/pub/products/search?_from=0&_to=49',
    ];

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Código postal para cookie de localización VTEX (vtex_segment / location).
     * Configurable en config('services.elektra.codigo_postal') o env ELEKTRA_CODIGO_POSTAL (ej. 01210).
     */
    protected function getCodigoPostal(): string
    {
        return (string) config('services.elektra.codigo_postal', '01210');
    }

    /**
     * Cabeceras y sesión mismo ADN que Coppel: Chrome + cookie de localización VTEX.
     * Para peticiones a /api/ añade Accept: application/json.
     *
     * @return array<string, mixed>
     */
    protected function getOpcionesPeticion(string $url): array
    {
        $cp = $this->getCodigoPostal();
        $cookie = 'vtex_segment=' . $cp . '; location=' . $cp;
        $headers = ['Cookie' => $cookie];
        if (str_contains($url, '/api/')) {
            $headers['Accept'] = 'application/json';
        }

        return ['headers' => $headers];
    }

    /**
     * Recolecta: primero API de ofertas (VTEX catalog search); si no hay datos, HTML /ofertas y __STATE__; luego ruta alternativa.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $this->peticionesRealizadas = 0;
        $base = rtrim($this->getUrlBase(), '/');

        $productos = $this->recolectarDesdeApi($base);
        if ($productos !== []) {
            Log::info('ElektraMotor: productos obtenidos vía API catalog', ['cantidad' => count($productos)]);

            return $productos;
        }

        $urlOfertas = $base . '/' . ltrim($this->getRutaOfertas(), '/');
        $resultado = $this->realizarPeticion($urlOfertas);

        if ($resultado !== null && $resultado['status'] === 200) {
            $productos = $this->extraerProductosDeRespuesta($resultado['body'], $urlOfertas);
            if ($productos !== []) {
                return $productos;
            }
        }

        $urlCategoria = $base . '/' . ltrim(self::RUTA_ALTERNATIVA, '/');
        Log::info('ElektraMotor: intentando ruta alternativa (sin productos en ofertas)', ['url_alternativa' => $urlCategoria]);
        $resultadoAlt = $this->realizarPeticion($urlCategoria);

        if ($resultadoAlt === null || $resultadoAlt['status'] !== 200) {
            return [];
        }

        return $this->extraerProductosDeRespuesta($resultadoAlt['body'], $urlCategoria);
    }

    /**
     * Intenta obtener ofertas desde la API VTEX (catalog_system pub products search).
     * Filtra productos con discount o price_promotion (ListPrice > Price).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function recolectarDesdeApi(string $base): array
    {
        $cabeceras = $this->obtenerCabecerasNavegador($base);
        $opciones = $this->getOpcionesPeticion($base . '/api/catalog_system/pub/products/search');
        if (isset($opciones['headers']) && is_array($opciones['headers'])) {
            $cabeceras = array_merge($cabeceras, $opciones['headers']);
        }

        foreach (self::API_OFERTAS_CANDIDATOS as $ruta) {
            $url = $base . $ruta;
            $this->pausarEntrePeticiones();
            $request = Http::withHeaders($cabeceras)->timeout(12)->connectTimeout(10);
            $request = HttpRastreador::conProxySiTexto($request, $url);

            try {
                $respuesta = $request->get($url);
                $this->peticionesRealizadas++;
            } catch (\Throwable $e) {
                Log::debug('ElektraMotor: API no disponible', ['url' => $url, 'mensaje' => $e->getMessage()]);
                continue;
            }

            if (! $respuesta->successful()) {
                continue;
            }

            $data = $respuesta->json();
            if (! is_array($data)) {
                continue;
            }

            $items = $data;
            if (isset($data['products']) && is_array($data['products'])) {
                $items = $data['products'];
            } elseif (isset($data['data']) && is_array($data['data'])) {
                $items = $data['data'];
            }

            $productos = $this->mapearDesdeApiCatalog($items);
            if ($productos !== []) {
                return $productos;
            }
        }

        return [];
    }

    /**
     * Mapea respuesta de /api/catalog_system/pub/products/search a nuestro formato.
     * Prioriza productos con descuento (ListPrice > Price o price_promotion).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdeApiCatalog(array $items): array
    {
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $normalizado = $this->normalizarItemDesdeApiCatalog($item);
            if ($normalizado !== null) {
                $productos[] = $normalizado;
            }
        }

        return $productos;
    }

    /**
     * Normaliza un ítem de la API catalog VTEX (items[].sellers[].commertialOffer).
     * Solo incluye productos con precio de oferta o descuento (ListPrice > Price).
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItemDesdeApiCatalog(array $item): ?array
    {
        $nombre = (string) ($item['productName'] ?? $item['name'] ?? $item['title'] ?? '');
        $productId = (string) ($item['productId'] ?? $item['id'] ?? '');
        if ($nombre === '' && $productId === '') {
            return null;
        }

        $precioOriginal = 0.0;
        $precioOferta = 0.0;
        $items = $item['items'] ?? [];
        $primerItem = is_array($items) ? ($items[0] ?? []) : [];
        $sellers = $primerItem['sellers'] ?? [];
        $offer = (is_array($sellers) && isset($sellers[0])) ? ($sellers[0]['commertialOffer'] ?? $sellers[0]['commercialOffer'] ?? []) : [];

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

        $itemsArr = $item['items'] ?? [];
        $primerItemImg = is_array($itemsArr) && isset($itemsArr[0]) ? $itemsArr[0] : [];
        $imagenes = $primerItemImg['images'] ?? [];
        $imagenUrl = (is_array($imagenes) && isset($imagenes[0])) ? ($imagenes[0]['imageUrl'] ?? $imagenes[0]['url'] ?? null) : null;
        $imagenUrl = $imagenUrl ?? $item['image'] ?? $item['thumbnail'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? null;
        }
        $link = $item['link'] ?? $item['url'] ?? $item['linkText'] ?? '';
        $urlOriginal = is_string($link) && $link !== '' && str_starts_with($link, 'http') ? $link : self::URL_BASE . '/' . ltrim((string) $link, '/');

        return [
            'sku_tienda' => 'ELE-' . ($productId ?: substr(md5($nombre), 0, 12)),
            'nombre' => $nombre ?: 'Producto Elektra',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ?: null,
        ];
    }

    /**
     * Extracción: primero __STATE__ (VTEX); si no hay o está vacío, fallback por DOM con clases VTEX.
     * Si no se extrae nada (o respuesta 403), se registra la respuesta para diagnóstico.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerProductosDeRespuesta(string $body, string $urlPagina): array
    {
        $productos = [];

        // Elektra usa VTEX: buscar __STATE__ (JSON gigante con productos).
        if (preg_match('/__STATE__\s*=\s*(\{.+\})\s*;?\s*<\/script>/s', $body, $coincidencias)) {
            $productos = $this->mapearDesdeVtexState($coincidencias[1]);
        }

        if (empty($productos)) {
            $productos = $this->extraerDesdeDom($body);
        }

        // No guardar productos con precio 0 para no ensuciar la base de datos.
        $productos = array_values(array_filter($productos, function (array $p): bool {
            $precioOriginal = (float) ($p['precio_original'] ?? 0);
            $precioOferta = (float) ($p['precio_oferta'] ?? 0);

            return $precioOriginal > 0 || $precioOferta > 0;
        }));

        if (empty($productos)) {
            $this->registrarRespuestaParaDebug($body, $urlPagina, 'ElektraMotor');
            // Guardar HTML en archivo temporal para revisar qué ve el servidor realmente
            $rutaDebug = storage_path('logs/last_elektra_html.html');
            if (file_put_contents($rutaDebug, $body) !== false) {
                Log::info('ElektraMotor: respuesta HTML guardada para diagnóstico', ['archivo' => $rutaDebug]);
            }
        } else {
            $fuente = str_contains($body, '__STATE__') ? 'VTEX __STATE__' : 'DOM';
            Log::info('ElektraMotor: productos extraídos', [
                'cantidad' => count($productos),
                'fuente' => $fuente,
            ]);
        }

        return $productos;
    }

    /**
     * Parsea el JSON __STATE__ de VTEX y obtiene la lista de productos.
     * Si la lista son IDs, resuelve el producto completo desde claves tipo "Product:id".
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, stock_disponible?: int}>
     */
    protected function mapearDesdeVtexState(string $jsonStr): array
    {
        $data = json_decode($jsonStr, true);
        if (! is_array($data)) {
            return [];
        }

        $items = $data['search']['products'] ?? $data['search']['productSummaries'] ?? $data['productList'] ?? $data['products'] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        // Si son solo IDs (strings), resolver producto completo desde __STATE__ (ej. "Product:123").
        $resueltos = [];
        foreach (array_slice($items, 0, 50) as $entrada) {
            if (is_string($entrada)) {
                $producto = $data['Product:' . $entrada] ?? $data['ProductSummary:' . $entrada] ?? null;
                if (is_array($producto)) {
                    $resueltos[] = $producto;
                }
            } elseif (is_array($entrada)) {
                $resueltos[] = $entrada;
            }
        }
        if ($resueltos !== []) {
            return $this->mapearItems($resueltos, $data);
        }

        if ($items !== []) {
            return $this->mapearItems($items, $data);
        }

        // VTEX a veces guarda productos bajo claves con prefijo (ej. "searchResult.v2").
        foreach ($data as $valor) {
            if (! is_array($valor)) {
                continue;
            }
            $lista = $valor['products'] ?? $valor['productSummaries'] ?? $valor['items'] ?? null;
            if (is_array($lista) && count($lista) > 0) {
                $primero = $lista[0] ?? [];
                if (is_array($primero) && (isset($primero['name']) || isset($primero['productName']) || isset($primero['title']))) {
                    return $this->mapearItems($lista, $data);
                }
            }
        }

        // Recorrer todas las claves del state por si los productos están en "ProductSummary:id" o "Product:id".
        $porClave = [];
        foreach ($data as $key => $valor) {
            if (! is_array($valor) || ! is_string($key)) {
                continue;
            }
            $tieneNombre = isset($valor['name']) || isset($valor['productName']) || isset($valor['title']);
            $tieneRef = isset($valor['productId']) || isset($valor['id']) || isset($valor['items']);
            if ($tieneNombre && $tieneRef && (str_contains($key, 'Product') || str_contains($key, 'Summary'))) {
                $porClave[] = $valor;
            }
        }
        if (count($porClave) > 0) {
            return $this->mapearItems(array_slice($porClave, 0, 50), $data);
        }

        return [];
    }

    /**
     * Fallback: extracción por DOM usando contenedores VTEX (.vtex-product-summary-2-x-container).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeDom(string $body): array
    {
        $productos = [];
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        if (! @$dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR)) {
            libxml_clear_errors();

            return [];
        }
        $xpath = new DOMXPath($dom);

        // Contenedores VTEX típicos: .vtex-product-summary-2-x-container, .vtex-product-summary-2-x-element
        $contenedores = $xpath->query("//*[contains(@class, 'vtex-product-summary-2-x-container') or contains(@class, 'vtex-product-summary-2-x-element')]");
        if ($contenedores === false || $contenedores->length === 0) {
            $contenedores = $xpath->query("//*[contains(@class, 'product-summary') or contains(@class, 'productSummary')]");
        }

        if ($contenedores === false || $contenedores->length === 0) {
            libxml_clear_errors();

            return [];
        }

        $vistos = [];
        foreach ($contenedores as $nodo) {
            $sku = $nodo instanceof \DOMElement ? trim($nodo->getAttribute('data-productid') ?: $nodo->getAttribute('data-sku') ?: '') : '';
            $nombre = $this->extraerTexto($xpath, $nodo, ".//*[contains(@class,'product-name')] | .//*[contains(@class,'productName')] | .//h2 | .//h3 | .//*[contains(@class,'title')] | .//*[contains(@class,'name')] | .//a[contains(@href,'/p/')]");
            // Precio: VTEX usa sellingPrice, listPrice; texto o atributos data-price/data-selling-price.
            $precioOfertaTexto = $this->extraerTexto($xpath, $nodo, ".//*[contains(@class,'sellingPrice')] | .//*[contains(@class,'selling-price')] | .//*[contains(@class,'price_sales')] | .//*[contains(@class,'product-price')] | .//*[contains(@class,'price')]");
            $precioListaTexto = $this->extraerTexto($xpath, $nodo, ".//*[contains(@class,'listPrice')] | .//*[contains(@class,'list-price')] | .//s | .//del");
            $precioTexto = $precioOfertaTexto ?: $precioListaTexto;
            if ($precioTexto === '') {
                $precioTexto = $this->extraerPrecioDesdeAtributos($xpath, $nodo);
            }
            $imagenUrl = $this->extraerImagen($xpath, $nodo);
            $urlOriginal = $this->extraerEnlace($xpath, $nodo);

            if ($nombre === '' && $precioTexto === '') {
                continue;
            }

            $precioOferta = $this->parsearPrecio($precioOfertaTexto);
            $precioLista = $this->parsearPrecio($precioListaTexto);
            $precio = $precioOferta ?? $precioLista;
            if ($precio === null) {
                $precio = $this->parsearPrecio($precioTexto);
            }
            $precioOriginal = $precioLista ?? $precio ?? 0.0;
            if ($precioOriginal <= 0 && $precio !== null) {
                $precioOriginal = $precio;
            }

            $id = $sku ?: $nombre ?: $precioTexto;
            if (isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;

            $productos[] = [
                'sku_tienda' => 'ELE-' . ($sku ?: substr(md5($id), 0, 12)),
                'nombre' => $nombre ?: 'Producto Elektra',
                'precio_original' => round($precioOriginal, 2),
                'precio_oferta' => $precio !== null ? round($precio, 2) : null,
                'imagen_url' => $imagenUrl ?: null,
                'url_original' => $urlOriginal ?: null,
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

        return trim($nodes->item(0)->textContent ?? '');
    }

    /** Obtiene precio desde atributos data-selling-price, data-price o data-list-price en el subárbol. */
    private function extraerPrecioDesdeAtributos(DOMXPath $xpath, \DOMNode $nodo): string
    {
        $nodes = $xpath->query(".//*[@data-selling-price or @data-price or @data-list-price]", $nodo);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        foreach ($nodes as $el) {
            if (! $el instanceof \DOMElement) {
                continue;
            }
            $v = $el->getAttribute('data-selling-price') ?: $el->getAttribute('data-price') ?: $el->getAttribute('data-list-price');
            if ($v !== '' && is_numeric(str_replace([',', ' '], ['.', ''], $v))) {
                return $v;
            }
        }

        return '';
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
     * @param  array<string, mixed>  $state  __STATE__ completo por si hay que resolver precio por skuId.
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, stock_disponible?: int}>
     */
    protected function mapearItems(array $items, array $state = []): array
    {
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            $m = $this->normalizarItem($item, $state);
            if ($m !== null) {
                $productos[] = $m;
            }
        }

        return $productos;
    }

    /**
     * Normaliza un ítem VTEX. El precio real está en items[0].sellers[0].commertialOffer.Price
     * (o selectedItem.sellers[0].commertialOffer). Stock en AvailableQuantity.
     * Si no viene en el ítem, se resuelve desde __STATE__ con la clave skuId:itemId.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $state  __STATE__ completo para resolver por skuId.
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, stock_disponible?: int}|null
     */
    protected function normalizarItem(array $item, array $state = []): ?array
    {
        $sku = (string) ($item['sku'] ?? $item['productId'] ?? $item['id'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['productName'] ?? $item['title'] ?? '');
        if ($sku === '' && $nombre === '') {
            return null;
        }

        $precioOriginal = 0.0;
        $precioOferta = 0.0;
        $stockDisponible = 0;

        // 1) __STATE__: precio en items[0].sellers[0].commertialOffer.Price (fuente principal VTEX)
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
            $stockDisponible = (int) ($offer['AvailableQuantity'] ?? $offer['availableQuantity'] ?? 0);
        }

        // 2) selectedItem.sellers[0].commertialOffer (ProductSummary)
        if ($precioOferta <= 0) {
            $selected = $item['selectedItem'] ?? $item['selectedSku'] ?? [];
            $sellersSel = is_array($selected) ? ($selected['sellers'] ?? []) : [];
            $seller0 = is_array($sellersSel) ? ($sellersSel[0] ?? []) : [];
            $offerSel = $seller0['commertialOffer'] ?? $seller0['commercialOffer'] ?? [];
            if (is_array($offerSel)) {
                $precioOferta = (float) ($offerSel['Price'] ?? $offerSel['price'] ?? 0);
                $precioOriginal = (float) ($offerSel['ListPrice'] ?? $offerSel['listPrice'] ?? $precioOferta);
                if ($precioOriginal <= 0) {
                    $precioOriginal = $precioOferta;
                }
                $stockDisponible = (int) ($offerSel['AvailableQuantity'] ?? $offerSel['availableQuantity'] ?? 0);
            }
        }

        // 3) Resolver desde __STATE__ por skuId (clave "skuId:itemId" o "SKU:itemId")
        if ($precioOferta <= 0 && $state !== []) {
            $itemId = null;
            if (is_array($primerItem) && isset($primerItem['itemId'])) {
                $itemId = $primerItem['itemId'];
            } elseif (is_array($items) && isset($items[0]) && is_string($items[0])) {
                $itemId = $items[0];
            } elseif (isset($item['itemId'])) {
                $itemId = $item['itemId'];
            }
            if ($itemId !== null) {
                $skuState = $state['skuId:' . $itemId] ?? $state['SKU:' . $itemId] ?? $state['Item:' . $itemId] ?? $state[$itemId] ?? null;
                if (is_array($skuState)) {
                    $sellersSku = $skuState['sellers'] ?? [];
                    $sellerSku = is_array($sellersSku) ? ($sellersSku[0] ?? []) : [];
                    $offerSku = $sellerSku['commertialOffer'] ?? $sellerSku['commercialOffer'] ?? [];
                    if (is_array($offerSku)) {
                        $precioOferta = (float) ($offerSku['Price'] ?? $offerSku['price'] ?? 0);
                        $precioOriginal = (float) ($offerSku['ListPrice'] ?? $offerSku['listPrice'] ?? $precioOferta);
                        if ($precioOriginal <= 0) {
                            $precioOriginal = $precioOferta;
                        }
                        $stockDisponible = (int) ($offerSku['AvailableQuantity'] ?? $offerSku['availableQuantity'] ?? 0);
                    }
                }
            }
        }

        // 4) Fallback claves planas en el ítem (solo si aún no tenemos precio)
        if ($precioOferta <= 0) {
            $precioOriginal = (float) ($item['listPrice'] ?? $item['price'] ?? 0);
            $precioOferta = (float) ($item['salePrice'] ?? $item['currentPrice'] ?? $item['price'] ?? 0);
            if ($precioOriginal <= 0) {
                $precioOriginal = $precioOferta;
            }
        }

        // No incluir producto si el precio sigue en 0 (evitar ensuciar la base de datos).
        if ($precioOferta <= 0 && $precioOriginal <= 0) {
            return null;
        }

        $skuTienda = 'ELE-' . ($sku ?: substr(md5($nombre), 0, 12));
        $imagenUrl = $item['image'] ?? $item['imageUrl'] ?? $item['thumbnail'] ?? $item['images'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? (isset($imagenUrl[0]['url']) ? $imagenUrl[0]['url'] : null);
        }
        $urlOriginal = $item['url'] ?? $item['link'] ?? $item['slug'] ?? null;
        if (is_string($urlOriginal) && $urlOriginal !== '' && ! str_starts_with($urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim($urlOriginal, '/');
        }

        $resultado = [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Elektra',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
        if ($stockDisponible > 0) {
            $resultado['stock_disponible'] = $stockDisponible;
        }

        return $resultado;
    }

    /**
     * Cuando no se extraen productos (o hay 403), registra la respuesta para ver si es Cloudflare o error de selectores.
     */
    protected function registrarRespuestaParaDebug(string $body, string $urlPagina, string $contexto = 'ElektraMotor'): void
    {
        $longitud = strlen($body);
        $tieneState = str_contains($body, '__STATE__');
        $titulo = '';
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $body, $m)) {
            $titulo = trim(strip_tags($m[1]));
        }
        $inicio = mb_substr($body, 0, 800);

        Log::warning($contexto . ': extracción fallida. Revisar si es Cloudflare o selectores.', [
            'url' => $urlPagina,
            'longitud_body' => $longitud,
            'tiene___STATE__vtex' => $tieneState,
            'titulo_pagina' => $titulo,
            'inicio_html' => $inicio,
        ]);
    }
}
