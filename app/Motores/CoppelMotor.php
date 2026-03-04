<?php

namespace App\Motores;

use Illuminate\Support\Facades\Http;

/**
 * Motor de rastreo masivo para Coppel México.
 * Descubre subcategorías desde /ofertas, recorre cada una con _rsc=1ei8z, extrae productos
 * (nombre, precio lista, precio oferta, imagen completa, SKU único) y soporta paginación.
 * Productos se vinculan a categoria_origen para persistencia.
 *
 * Regla Filament: Si "Permitir descuento adicional" está apagado, el bot envía el precio de tienda
 * sin modificaciones (CalculadoraOfertas + NotificadorTelegram).
 */
class CoppelMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.coppel.com';

    protected const RUTA_OFERTAS = 'l/ofertas';

    /** Página de ofertas para descubrir enlaces de subcategorías (Celulares, Electrónica, etc.). */
    protected const URL_OFERTAS_DISCOVERY = 'https://www.coppel.com/ofertas';

    /** Parámetro RSC: pide a Next.js solo datos, no HTML de diseño. */
    protected const PARAM_RSC = '_rsc=1ei8z';

    /** URL alternativa conocida (Ver más). */
    protected const RUTA_ALTERNATIVA = 'sd/RB2514EPMTPEMOODS';

    /**
     * Lista fija de categorías de ofertas (Coppel carga enlaces por JS, el discovery HTML suele fallar).
     * path relativo a URL_BASE, categoria = nombre para categoria_origen.
     *
     * @var array<int, array{path: string, categoria: string}>
     */
    protected const CATEGORIAS_OFERTAS_FIJAS = [
        ['path' => 'l/ofertas', 'categoria' => 'Ofertas'],
        ['path' => 'ofertas-de-la-semana', 'categoria' => 'Ofertas de la semana'],
        ['path' => 'l/ofertas-muebles', 'categoria' => 'Ofertas muebles'],
        ['path' => 'l/ofertas-hogar', 'categoria' => 'Ofertas hogar'],
        ['path' => 'l/ofertas-linea-blanca', 'categoria' => 'Ofertas línea blanca'],
        ['path' => 'sd/RB2514EPMTPEMOODS', 'categoria' => 'Ver más'],
    ];

    /** Límite de paginación por categoría (evita bucles y acelera; 404 en algunas rutas). */
    protected const MAX_PAGINAS_POR_CATEGORIA = 5;

    /** Máximo de productos por página al extraer (sin recorte si hay más). */
    protected const MAX_PRODUCTOS_POR_PAGINA = 500;

    /** Si está definido, el motor deja de pedir páginas al llegar a este número de productos (modo ágil). */
    protected ?int $limiteProductos = null;

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
        return 'coppel';
    }

    /**
     * Limita cuántos productos se recolectan (para pruebas rápidas con --max=10 o --max=20).
     */
    public function setLimiteProductos(?int $n): void
    {
        $this->limiteProductos = $n > 0 ? $n : null;
    }

    /**
     * Cabeceras para peticiones RSC: Next.js devuelve flujo de datos en lugar de HTML.
     *
     * @return array<string, mixed>
     */
    protected function getOpcionesPeticion(string $url): array
    {
        $opciones = parent::getOpcionesPeticion($url);
        // Descubrimiento: timeout corto para no frenar el rastreo (lista fija ya da varias categorías)
        if (str_starts_with($url, self::URL_OFERTAS_DISCOVERY)) {
            $opciones['timeout'] = 3;
            $opciones['connect_timeout'] = 2;
        }
        if (str_contains($url, self::PARAM_RSC) || str_contains($url, '_rsc=')) {
            $headers = $this->obtenerCabecerasNavegador($this->getUrlBase());
            $headers['Accept'] = 'text/x-component';
            $headers['RSC'] = '1';
            $opciones['headers'] = $headers;
        }

        return $opciones;
    }

    /**
     * Rastreo masivo: peticiones concurrentes con Http::pool(), timeouts estrictos y máximo 5 páginas por categoría.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, stock_disponible?: int, categoria_origen?: string}>
     */
    public function recolectarDatos(): array
    {
        $this->peticionesRealizadas = 0;
        $base = rtrim($this->getUrlBase(), '/');

        $entradas = $this->descubrirUrlsCategorias($base);
        if (empty($entradas)) {
            $entradas = [
                ['url' => $base . '/' . ltrim($this->getRutaOfertas(), '/'), 'categoria' => 'Ofertas'],
                ['url' => $base . '/' . ltrim(self::RUTA_ALTERNATIVA, '/'), 'categoria' => 'Ver más'],
            ];
        }

        $maxPaginas = self::MAX_PAGINAS_POR_CATEGORIA;
        $requests = [];
        foreach ($entradas as $entrada) {
            $urlCategoria = $entrada['url'];
            $categoria = $entrada['categoria'];
            for ($pagina = 1; $pagina <= $maxPaginas; $pagina++) {
                $urlConRsc = $this->urlConRscYPagina($urlCategoria, $pagina);
                $key = $urlConRsc;
                $requests[$key] = ['url' => $urlConRsc, 'categoria' => $categoria];
            }
        }

        $headers = $this->obtenerCabecerasNavegador($base);
        $headers['Accept'] = 'text/x-component';
        $headers['RSC'] = '1';

        $responses = Http::pool(function ($pool) use ($requests, $headers) {
            foreach ($requests as $key => $req) {
                $pool->as($key)
                    ->withHeaders($headers)
                    ->timeout(5)
                    ->retry(2, 100)
                    ->withOptions(['verify' => false])
                    ->get($req['url']);
            }
        });

        $todosPorSku = [];
        foreach ($responses as $urlConRsc => $response) {
            // El pool devuelve excepciones (RequestException) cuando la petición falla; ignorar
            if ($response instanceof \Throwable) {
                continue;
            }
            if (! $response->successful()) {
                continue;
            }
            $categoria = $requests[$urlConRsc]['categoria'];
            $productos = $this->extraerProductosDeRespuesta($response->body(), $urlConRsc, $categoria);
            foreach ($productos as $p) {
                $key = $p['sku_tienda'];
                if (! isset($todosPorSku[$key])) {
                    $todosPorSku[$key] = $p;
                }
            }
            $this->peticionesRealizadas++;
            if ($this->limiteProductos !== null && count($todosPorSku) >= $this->limiteProductos) {
                break;
            }
        }

        $lista = array_values($todosPorSku);
        if ($this->limiteProductos !== null && count($lista) > $this->limiteProductos) {
            $lista = array_slice($lista, 0, $this->limiteProductos);
        }

        return $lista;
    }

    /**
     * Construye la URL con _rsc y, si aplica, parámetro de página.
     */
    protected function urlConRscYPagina(string $urlBase, int $pagina): string
    {
        $sep = str_contains($urlBase, '?') ? '&' : '?';
        $url = $urlBase . $sep . self::PARAM_RSC;
        if ($pagina > 1) {
            $url .= '&page=' . $pagina;
        }

        return $url;
    }

    /**
     * Devuelve URLs de categorías de ofertas: primero lista fija (rápido, varias categorías),
     * opcionalmente enriquece con discovery si hay tiempo (Coppel suele cargar enlaces por JS).
     *
     * @return array<int, array{url: string, categoria: string}>
     */
    protected function descubrirUrlsCategorias(string $base): array
    {
        $vistas = [];
        $entradas = [];

        // 1) Lista fija: siempre varias categorías sin depender del HTML (más ágil, evita discovery lento)
        foreach (self::CATEGORIAS_OFERTAS_FIJAS as $item) {
            $url = $base . '/' . ltrim($item['path'], '/');
            $norm = preg_replace('/\?.*/', '', $url);
            if (! isset($vistas[$norm])) {
                $vistas[$norm] = true;
                $entradas[] = ['url' => $norm, 'categoria' => $item['categoria']];
            }
        }

        // 2) Opcional: discovery con timeout corto (getOpcionesPeticion usa 3s para discovery) para añadir más /sd/ si el HTML los trae
        $urlDiscovery = self::URL_OFERTAS_DISCOVERY;
        $resultado = $this->realizarPeticion($urlDiscovery);

        if ($resultado !== null && $resultado['status'] === 200) {
            $body = $resultado['body'];
            if (preg_match_all('/href\s*=\s*["\'](?:https?:\/\/[^"\']*coppel\.com)?(\/(?:l\/ofertas[^"\']*|sd\/[A-Za-z0-9_-]+)[^"\']*)["\']/', $body, $m)) {
                foreach ($m[1] as $path) {
                    $path = trim(preg_replace('/#.*/', '', $path), '/');
                    if ($path === '' || $path === 'ofertas') {
                        continue;
                    }
                    $path = '/' . $path;
                    $url = str_starts_with($path, 'http') ? $path : $base . '/' . ltrim($path, '/');
                    $norm = preg_replace('/\?.*/', '', $url);
                    if (isset($vistas[$norm])) {
                        continue;
                    }
                    $vistas[$norm] = true;
                    $entradas[] = ['url' => $norm, 'categoria' => $this->nombreCategoriaDesdeUrl($path)];
                }
            }
        }

        return $entradas;
    }

    /**
     * Deriva un nombre legible de categoría a partir del path (ej. /sd/RB2514EPM -> "RB2514EPM").
     */
    protected function nombreCategoriaDesdeUrl(string $path): string
    {
        $path = trim($path, '/');
        $partes = explode('/', $path);
        $ultimo = end($partes);
        if ($ultimo !== '' && $ultimo !== 'ofertas') {
            return ucfirst(str_replace('-', ' ', $ultimo));
        }

        return 'Ofertas';
    }

    /**
     * Extracción: primero __NEXT_DATA__ (Next.js); fallback __STATE__ (VTEX); luego flujo RSC.
     * Si se pasa $categoria, se añade a cada producto para persistencia.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, stock_disponible?: int, categoria_origen?: string}>
     */
    protected function extraerProductosDeRespuesta(string $body, string $urlPagina, ?string $categoria = null): array
    {
        $productos = [];

        // Guardar siempre el último HTML recibido para poder revisar en terminal qué ve el servidor.
        $dir = storage_path('logs');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $rutaDebug = $dir . DIRECTORY_SEPARATOR . 'debug_coppel.html';
        file_put_contents($rutaDebug, $body);

        if (preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.+?)<\/script>/s', $body, $coincidencias)) {
            $json = json_decode(trim($coincidencias[1]), true);
            if (is_array($json)) {
                $productos = $this->mapearDesdeNextData($json);
            }
        }

        if (empty($productos) && preg_match('/__STATE__\s*=\s*(\{.+\})\s*;?\s*<\/script>/s', $body, $coincidencias)) {
            $productos = $this->mapearDesdeVtexState($coincidencias[1]);
        }

        if (empty($productos)) {
            $productos = $this->extraerDesdeFlujoRsc($body, $urlPagina, $categoria);
        }

        if ($categoria !== null && $categoria !== '') {
            foreach ($productos as $i => $p) {
                $productos[$i]['categoria_origen'] = $categoria;
            }
        }

        return $productos;
    }

    /**
     * Extrae productos del flujo RSC de Next.js: nombre completo, precio lista, precio oferta,
     * imagen en alta resolución (URL completa), SKU único. Sin límite estricto por página (hasta MAX_PRODUCTOS_POR_PAGINA).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeFlujoRsc(string $body, string $urlPagina, ?string $categoria = null): array
    {
        $productos = [];
        $vistos = [];

        // 1) Buscador universal: bloques que contengan "title", "price" e "image" (objetos en arreglos profundos RSC)
        foreach ($this->extraerBloquesConTitlePriceImage($body) as $jsonStr) {
            $decoded = json_decode($jsonStr, true);
            if (! is_array($decoded)) {
                continue;
            }
            $item = $this->extraerItemDesdeObjetoRsc($decoded, $urlPagina);
            if ($item !== null) {
                $key = $item['sku_tienda'];
                if (! isset($vistos[$key])) {
                    $vistos[$key] = true;
                    $productos[] = $item;
                }
            }
        }

        // 2) Objetos {...} con llaves balanceadas (por si el universal no capturó todos)
        $offset = 0;
        $len = strlen($body);
        while ($offset < $len) {
            $pos = strpos($body, '{"', $offset);
            if ($pos === false) {
                break;
            }
            $depth = 0;
            $start = $pos;
            $i = $pos + 1;
            $end = -1;
            while ($i < $len) {
                $c = $body[$i];
                if ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    if ($depth === 0) {
                        $end = $i + 1;
                        break;
                    }
                    $depth--;
                } elseif ($c === '"' && $i > 0 && $body[$i - 1] !== '\\') {
                    while ($i < $len - 1) {
                        $i++;
                        if ($body[$i] === '\\') {
                            $i++;
                            continue;
                        }
                        if ($body[$i] === '"') {
                            break;
                        }
                    }
                }
                $i++;
                if ($i - $start > 6000) {
                    break;
                }
            }
            $offset = $pos + 2;
            if ($end === -1 || $end - $start > 6000) {
                continue;
            }
            $jsonStr = substr($body, $start, $end - $start);
            if (! (str_contains($jsonStr, '"title"') || str_contains($jsonStr, '"productName"')) || ! str_contains($jsonStr, '"price"') || ! (str_contains($jsonStr, '"image"') || str_contains($jsonStr, '"partNumber"'))) {
                continue;
            }
            $decoded = json_decode($jsonStr, true);
            if (! is_array($decoded)) {
                continue;
            }
            $item = $this->extraerItemDesdeObjetoRsc($decoded, $urlPagina);
            if ($item !== null) {
                $key = $item['sku_tienda'];
                if (! isset($vistos[$key])) {
                    $vistos[$key] = true;
                    $productos[] = $item;
                }
            }
        }

        // 3) Fallback: regex para precios y nombres sueltos (asociar por proximidad)
        if (empty($productos) && preg_match_all('/"((?:productId|sku|id)"\s*:\s*"[^"]*"|"(?:price|lowPrice|listPrice|precio)"\s*:\s*\d+(?:\.\d+)?|"(?:productName|name|title)"\s*:\s*"[^"]*")/s', $body, $m)) {
            $productos = $this->extraerProductosDesdeRegexRsc($body, $urlPagina);
        }

        return array_slice($productos, 0, self::MAX_PRODUCTOS_POR_PAGINA);
    }

    /**
     * Buscador universal: encuentra bloques que contengan "title" (o "productName"), "price" e "image"
     * en el flujo RSC (objetos dentro de arreglos profundos). Extrae el {...} balanceado que los contiene.
     *
     * @return array<int, string> fragmentos JSON
     */
    protected function extraerBloquesConTitlePriceImage(string $body): array
    {
        $bloques = [];
        $len = strlen($body);
        $ventana = 4000;

        // Buscar puntos que parezcan producto: tienen "price" y ("title" o "productName") y ("image" o "partNumber")
        if (! preg_match_all('/"(?:title|productName)"\s*:\s*"/', $body, $titulos, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($titulos[0] as $m) {
            $pos = $m[1];
            $inicio = max(0, $pos - 800);
            $fin = min($len, $pos + $ventana);
            $trozo = substr($body, $inicio, $fin - $inicio);
            if (! str_contains($trozo, '"price"') || (! str_contains($trozo, '"image"') && ! str_contains($trozo, '"partNumber"'))) {
                continue;
            }
            // Encontrar el { que abre el objeto: ir hacia atrás desde $pos
            $startObj = $pos;
            while ($startObj > $inicio && $body[$startObj] !== '{') {
                $startObj--;
            }
            if ($body[$startObj] !== '{') {
                continue;
            }
            $depth = 0;
            $endObj = -1;
            for ($i = $startObj; $i < min($len, $startObj + 6000); $i++) {
                $ch = $body[$i];
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $endObj = $i + 1;
                        break;
                    }
                } elseif ($ch === '"' && $i > 0 && $body[$i - 1] !== '\\') {
                    while ($i + 1 < $len) {
                        $i++;
                        if ($body[$i] === '\\') {
                            $i++;
                        } elseif ($body[$i] === '"') {
                            break;
                        }
                    }
                }
            }
            if ($endObj === -1) {
                continue;
            }
            $jsonStr = substr($body, $startObj, $endObj - $startObj);
            if (strlen($jsonStr) < 50) {
                continue;
            }
            $bloques[$jsonStr] = true;
        }

        return array_keys($bloques);
    }

    /**
     * Convierte valor de precio a float: quita '$', comas y espacios antes de castear.
     */
    protected static function limpiarPrecio(mixed $valor): float
    {
        if (is_numeric($valor)) {
            return (float) $valor;
        }
        $s = (string) $valor;
        $s = str_replace(['$', ',', ' '], '', $s);

        return (float) $s;
    }

    /**
     * Prefijo base para URLs de imágenes de Coppel cuando vienen incompletas.
     */
    protected const IMAGEN_COPPEL_BASE = 'https://cdn5.coppel.com';

    /**
     * Construye URL de imagen Coppel cuando la API no la envía.
     * Formato: partNumber "PM-2288453" → https://cdn5.coppel.com/pm/2288453-1.jpg
     */
    protected function construirUrlImagenDesdePartNumber(string $partNumber): ?string
    {
        $partNumber = trim($partNumber);
        if ($partNumber === '') {
            return null;
        }
        // Quitar prefijo COP- si vino el sku_tienda en lugar del partNumber crudo
        if (str_starts_with($partNumber, 'COP-')) {
            $partNumber = substr($partNumber, 4);
        }
        $partes = explode('-', $partNumber);
        if (count($partes) < 2) {
            return null;
        }
        $prefijo = strtolower($partes[0]);
        $id = $partes[1];

        return rtrim(self::IMAGEN_COPPEL_BASE, '/') . '/' . $prefijo . '/' . $id . '-1.jpg';
    }

    /**
     * Si el objeto tiene claves de precio y nombre, devuelve ítem normalizado; si no, null.
     * Coppel RSC: precio_original = price, precio_oferta = offerPrice (offerPrice 0 o ausente → solo un precio, ahorro "—").
     * Excluye productos con available=false o stock=0. Imagen: se completa con prefijo Coppel si es relativa.
     *
     * @param  array<string, mixed>  $obj
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, stock_disponible?: int}|null
     */
    protected function extraerItemDesdeObjetoRsc(array $obj, string $urlPagina): ?array
    {
        // Stock/available: no enviar ofertas de productos agotados
        if (isset($obj['available']) && $obj['available'] === false) {
            return null;
        }
        $stock = isset($obj['stock']) ? (int) $obj['stock'] : null;
        if ($stock !== null && $stock <= 0) {
            return null;
        }

        // Coppel RSC: precio_original = price. precio_oferta = offerPrice solo si offerPrice existe y es menor que price; si son iguales → null.
        $precioOriginal = self::limpiarPrecio($obj['price'] ?? $obj['listPrice'] ?? $obj['originalPrice'] ?? $obj['highPrice'] ?? $obj['regularPrice'] ?? $obj['precioOriginal'] ?? 0);
        $offerPriceRaw = $obj['offerPrice'] ?? null;
        $precioOfertaVal = null;
        if ($offerPriceRaw !== null && $offerPriceRaw !== '' && $offerPriceRaw !== 0 && $offerPriceRaw !== '0') {
            $oferta = self::limpiarPrecio($offerPriceRaw);
            if ($oferta > 0 && $oferta < $precioOriginal) {
                $precioOfertaVal = $oferta;
            }
        }
        if ($precioOriginal <= 0 && ($precioOfertaVal === null || $precioOfertaVal <= 0)) {
            return null;
        }
        if ($precioOriginal <= 0) {
            $precioOriginal = $precioOfertaVal;
        }

        $nombre = (string) ($obj['title'] ?? $obj['productName'] ?? $obj['name'] ?? '');
        $sku = (string) ($obj['partNumber'] ?? $obj['productId'] ?? $obj['sku'] ?? $obj['id'] ?? '');

        if ($nombre === '' && $sku === '') {
            return null;
        }

        // SKU desde URL si no vino (ej. /pdp/nombre-producto-pm-3548813 o ...-5032613)
        if ($sku === '' && preg_match('/\/([A-Za-z]+-)?(\d{5,})(?:\?|$|["\'])/', $urlPagina, $skuUrl)) {
            $sku = $skuUrl[2];
        }

        $skuTienda = 'COP-' . ($sku ?: substr(md5($nombre ?: 'item'), 0, 12));

        // Imagen: si viene incompleta (sin protocolo), añadir prefijo Coppel; si falta, construir desde partNumber
        $imagenUrl = $obj['image'] ?? $obj['imageUrl'] ?? $obj['thumbnail'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl['src'] ?? $imagenUrl[0] ?? null;
        }
        if (is_string($imagenUrl) && $imagenUrl !== '' && ! str_starts_with($imagenUrl, 'http')) {
            $imagenUrl = rtrim(self::IMAGEN_COPPEL_BASE, '/') . '/' . ltrim($imagenUrl, '/');
        }
        if (($imagenUrl === null || $imagenUrl === '') && $sku !== '') {
            $imagenUrl = $this->construirUrlImagenDesdePartNumber($sku);
        }

        $urlOriginal = $obj['href'] ?? $obj['url'] ?? $obj['link'] ?? $obj['slug'] ?? $obj['productUrl'] ?? null;
        if (is_string($urlOriginal) && $urlOriginal !== '' && ! str_starts_with($urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim($urlOriginal, '/');
        }

        $resultado = [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Coppel',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOfertaVal !== null ? round($precioOfertaVal, 2) : null,
            'imagen_url' => $imagenUrl !== null ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal !== null ? (string) $urlOriginal : null,
        ];
        if ($stock !== null && $stock > 0) {
            $resultado['stock_disponible'] = $stock;
        }

        return $resultado;
    }

    /**
     * Extracción por regex cuando no hay JSON bien formado: busca SKU en URLs, precios y nombres en el cuerpo.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerProductosDesdeRegexRsc(string $body, string $urlPagina): array
    {
        $productos = [];
        $base = self::URL_BASE;

        // SKUs desde URLs tipo /pdp/...-5032613
        if (preg_match_all('/\/pdp\/[^"\'?\s]*?-(\d{5,})(?:\?|["\']|$)/', $body, $skuMatches)) {
            $skus = array_unique($skuMatches[1]);
        } else {
            preg_match_all('/"(?:productId|sku)"\s*:\s*"(\d+)"/', $body, $skuMatches);
            $skus = array_unique($skuMatches[1] ?? []);
        }

        preg_match_all('/"(?:price|lowPrice|precio|offerPrice)"\s*:\s*([\d.,]+)/', $body, $priceMatches);
        $precios = array_map(fn ($v) => self::limpiarPrecio($v), $priceMatches[1] ?? []);
        preg_match_all('/"(?:listPrice|highPrice|originalPrice)"\s*:\s*([\d.,]+)/', $body, $listMatches);
        $listPrices = array_map(fn ($v) => self::limpiarPrecio($v), $listMatches[1] ?? []);

        preg_match_all('/"(?:productName|name|title)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $body, $nameMatches);
        $nombres = $nameMatches[1] ?? [];

        if (empty($precios)) {
            return [];
        }
        $n = min(50, count($precios));
        for ($i = 0; $i < $n; $i++) {
            $sku = $skus[$i] ?? ($skus[0] ?? '');
            $precio = $precios[$i] ?? ($precios[0] ?? 0.0);
            $listPrice = $listPrices[$i] ?? ($listPrices[0] ?? $precio);
            if ($listPrice <= 0) {
                $listPrice = $precio;
            }
            $nombre = $nombres[$i] ?? ($nombres[0] ?? 'Producto Coppel');
            if ($precio <= 0) {
                continue;
            }
            $productos[] = [
                'sku_tienda' => 'COP-' . ($sku ?: substr(md5($nombre), 0, 12)),
                'nombre' => $nombre,
                'precio_original' => round($listPrice, 2),
                'precio_oferta' => round($precio, 2),
                'imagen_url' => null,
                'url_original' => $sku ? $base . '/pdp/producto-' . $sku : null,
            ];
        }

        return $productos;
    }

    /**
     * Parsea el JSON __STATE__ de VTEX (misma lógica que Elektra).
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
     * Extrae productos desde __NEXT_DATA__: props -> pageProps -> initialData -> searchResult -> products.
     * Si esa ruta no existe, busca en el JSON cualquier arreglo cuyos elementos tengan productId o sku.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdeNextData(array $data): array
    {
        $items = $data['props']['pageProps']['initialData']['searchResult']['products'] ?? null;
        if (is_array($items)) {
            return $this->mapearItemsNextData($items);
        }

        // Rutas alternativas
        $items = $data['props']['pageProps']['products'] ?? $data['props']['pageProps']['items'] ?? null;
        if (is_array($items)) {
            return $this->mapearItemsNextData($items);
        }

        // Buscar en el JSON cualquier arreglo que contenga elementos con productId o sku
        $items = $this->buscarArregloProductosEnJson($data);
        if ($items !== []) {
            return $this->mapearItemsNextData($items);
        }

        return [];
    }

    /**
     * Recorre el JSON y devuelve el primer arreglo cuyos elementos tengan productId o sku.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    protected function buscarArregloProductosEnJson(array $data): array
    {
        foreach ($data as $valor) {
            if (! is_array($valor)) {
                continue;
            }
            if (isset($valor[0]) && is_array($valor[0])) {
                $primero = $valor[0];
                if (isset($primero['productId']) || isset($primero['sku'])) {
                    return $valor;
                }
            }
            $encontrado = $this->buscarArregloProductosEnJson($valor);
            if ($encontrado !== []) {
                return $encontrado;
            }
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearItemsNextData(array $items): array
    {
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $m = $this->normalizarItemNextData($item);
            if ($m !== null) {
                $productos[] = $m;
            }
        }

        return $productos;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $state
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
     * Normaliza ítem VTEX: Price y ListPrice desde commertialOffer, AvailableQuantity para stock. Prefijo COP-.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $state
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

        if ($precioOferta <= 0) {
            $precioOriginal = (float) ($item['listPrice'] ?? $item['price'] ?? 0);
            $precioOferta = (float) ($item['salePrice'] ?? $item['currentPrice'] ?? $item['price'] ?? 0);
            if ($precioOriginal <= 0) {
                $precioOriginal = $precioOferta;
            }
        }

        if ($precioOferta <= 0 && $precioOriginal <= 0) {
            return null;
        }

        $skuTienda = 'COP-' . ($sku ?: substr(md5($nombre), 0, 12));
        $imagenUrl = $item['image'] ?? $item['imageUrl'] ?? $item['thumbnail'] ?? $item['images'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? null;
        }
        $urlOriginal = $item['url'] ?? $item['link'] ?? $item['slug'] ?? null;
        if (is_string($urlOriginal) && $urlOriginal !== '' && ! str_starts_with($urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim($urlOriginal, '/');
        }

        $resultado = [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Coppel',
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
     * Normaliza ítem desde __NEXT_DATA__: productName, price (oferta), listPrice (original), image. Prefijo COP-.
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItemNextData(array $item): ?array
    {
        $sku = (string) ($item['sku'] ?? $item['productId'] ?? $item['partNumber'] ?? $item['id'] ?? '');
        $nombre = (string) ($item['productName'] ?? $item['name'] ?? $item['title'] ?? '');
        if ($sku === '' && $nombre === '') {
            return null;
        }

        $skuTienda = 'COP-' . ($sku ?: substr(md5($nombre), 0, 12));

        // Precio oferta y original; limpieza de $ y comas. originalPrice/highPrice como fallback de lista
        $precioOferta = self::limpiarPrecio($item['price'] ?? $item['offerPrice'] ?? $item['currentPrice'] ?? $item['salePrice'] ?? 0);
        $precioOriginal = self::limpiarPrecio($item['listPrice'] ?? $item['originalPrice'] ?? $item['highPrice'] ?? $item['regularPrice'] ?? $item['price'] ?? 0);
        if ($precioOriginal <= 0) {
            $precioOriginal = $precioOferta;
        }

        // Imagen: si es relativa, prefijo Coppel
        $imagenUrl = $item['image'] ?? $item['imageUrl'] ?? $item['thumbnail'] ?? $item['images'][0] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl['src'] ?? $imagenUrl[0] ?? null;
            if (is_array($imagenUrl)) {
                $imagenUrl = $imagenUrl['url'] ?? $imagenUrl['src'] ?? null;
            }
        }
        if (is_string($imagenUrl) && $imagenUrl !== '' && ! str_starts_with($imagenUrl, 'http')) {
            $imagenUrl = rtrim(self::IMAGEN_COPPEL_BASE, '/') . '/' . ltrim($imagenUrl, '/');
        }

        $urlOriginal = $item['href'] ?? $item['url'] ?? $item['link'] ?? $item['slug'] ?? $item['productUrl'] ?? null;
        if (is_string($urlOriginal) && $urlOriginal !== '' && ! str_starts_with($urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim($urlOriginal, '/');
        }

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Coppel',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
    }
}
