<?php

namespace App\Motores;

use App\Models\Configuracion;
use App\Services\EstadoMotorService;
use App\Services\MercadoLibreTokenService;
use App\Support\HttpRastreador;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Motor híbrido para Mercado Libre México: API de promociones (prioridad) y scraping como respaldo.
 *
 * - Si hay access_token válido, intenta GET api.mercadolibre.com/promotions/search?site_id=MLM&type=ALL (con proxy).
 * - Si la API falla (403, token expirado, app no certificada), ejecuta rastreoPorScraping() (página de ofertas HTML).
 * - Comisión: micosmtics=187001804 en todas las URLs.
 */
class MercadoLibreMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.mercadolibre.com.mx';

    protected const RUTA_OFERTAS = 'ofertas';

    /** API de promociones (prioridad cuando hay token). */
    private const URL_PROMOTIONS_API = 'https://api.mercadolibre.com/promotions/search?site_id=MLM&type=ALL';

    /** URL de la página de ofertas (scraping). */
    private const URL_OFERTAS = 'https://www.mercadolibre.com.mx/ofertas';

    /** URL de búsqueda segura para validar túnel si ofertas falla (403/SSL). */
    private const URL_BUSQUEDA_FALLBACK = 'https://www.mercadolibre.com.mx/instax';

    /** ID de afiliado ML por defecto (comisión 187001804). */
    private const ID_AFILIADO_FALLBACK = '187001804';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Motor híbrido: intenta primero API de promociones (con token y proxy); si falla, scraping.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, origen_rastreo?: string}>
     */
    public function recolectarDatos(): array
    {
        $token = MercadoLibreTokenService::obtenerAccessTokenValido();
        if ($token !== null && $token !== '') {
            $productos = $this->obtenerOfertasViaApi();
            if (! empty($productos)) {
                return $productos;
            }
            Log::info('MercadoLibreMotor: API de promociones sin ítems; intentando scraping.');
        } else {
            Log::info('MercadoLibreMotor: sin token OAuth; usando solo scraping.');
        }

        $productos = $this->rastreoPorScraping();
        if (empty($productos)) {
            Log::warning('MercadoLibreMotor: no se encontraron productos (API y scraping vacíos). Revisa: OAuth (Admin → Mercado Libre), proxy en Ajustes y storage/logs/laravel.log.');
        }
        return $productos;
    }

    /**
     * Obtiene ofertas vía API de promociones. Usa proxy configurado en Ajustes para evitar cURL 35 y bloqueos.
     * Mapea price, original_price, id, permalink/url al formato estándar (nombre, precio_original, precio_oferta, url_original).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, origen_rastreo: string}>
     */
    public function obtenerOfertasViaApi(): array
    {
        $token = MercadoLibreTokenService::obtenerAccessTokenValido();
        if ($token === null || $token === '') {
            Log::debug('MercadoLibreMotor: sin token, no se intenta API de promociones.');
            return [];
        }

        $apiUrl = Configuracion::getProxyUrl() !== null
            ? HttpRastreador::urlApiMlParaProxy(self::URL_PROMOTIONS_API)
            : self::URL_PROMOTIONS_API;

        try {
            $request = Http::withHeaders(HttpRastreador::headersNavegador())
                ->withToken($token)
                ->timeout(25)
                ->connectTimeout(15)
                ->withOptions(HttpRastreador::opcionesSslBase());
            $request = HttpRastreador::conProxy($request);
            $response = $request->get($apiUrl);
        } catch (\Throwable $e) {
            Log::warning('MercadoLibreMotor: API promociones falló (conexión)', ['mensaje' => $e->getMessage()]);
            return [];
        }

        if ($response->status() === 403) {
            Log::debug('MercadoLibreMotor: API promociones 403 esperado (app no certificada); pasando a scraping sin error.');
            return [];
        }
        if ($response->status() !== 200) {
            Log::warning('MercadoLibreMotor: API promociones respuesta no 200', ['status' => $response->status()]);
            return [];
        }

        $data = $response->json();
        if (! is_array($data)) {
            return [];
        }

        $items = $data['results'] ?? $data['content'] ?? $data['promotions'] ?? $data['items'] ?? [];
        if (! is_array($items) || empty($items)) {
            Log::debug('MercadoLibreMotor: API promociones sin ítems en la respuesta.');
            return [];
        }

        $productos = [];
        foreach (array_slice($items, 0, 80) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $normalizado = $this->mapearItemApiPromociones($item);
            if ($normalizado !== null) {
                $normalizado['origen_rastreo'] = 'API';
                $productos[] = $normalizado;
            }
        }

        if (! empty($productos)) {
            Log::info('MercadoLibreMotor: ofertas obtenidas vía API de promociones (con proxy)', ['cantidad' => count($productos)]);
            app(EstadoMotorService::class)->reactivar('Mercado Libre');
        }

        return $productos;
    }

    /**
     * Mapea un ítem de la API de promociones al formato estándar (nombre, precio_original, precio_oferta, url_original, etc.).
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    private function mapearItemApiPromociones(array $item): ?array
    {
        $id = (string) ($item['id'] ?? $item['item_id'] ?? '');
        $nombre = (string) ($item['title'] ?? $item['name'] ?? '');
        if ($id === '' && $nombre === '') {
            return null;
        }

        $precio = (float) ($item['price'] ?? $item['sale_price'] ?? 0);
        $precioOriginal = (float) ($item['original_price'] ?? $item['price'] ?? 0);
        if ($precioOriginal <= 0) {
            $precioOriginal = $precio;
        }
        if ($precio <= 0) {
            $precio = $precioOriginal;
        }

        $permalink = $item['permalink'] ?? $item['url'] ?? $item['link'] ?? '';
        if (is_string($permalink) && $permalink !== '' && ! str_starts_with($permalink, 'http')) {
            $permalink = self::URL_BASE . '/' . ltrim($permalink, '/');
        }
        $urlOriginal = is_string($permalink) && $permalink !== ''
            ? self::inyectarAfiliadoEnPermalink($permalink)
            : null;

        $imagenUrl = $item['thumbnail'] ?? $item['picture'] ?? $item['thumbnail_id'] ?? $item['thumbnail_url'] ?? $item['image'] ?? $item['picture_url'] ?? $item['photo'] ?? null;
        if ($imagenUrl === null || $imagenUrl === '') {
            $pictures = $item['pictures'] ?? $item['images'] ?? null;
            if (is_array($pictures) && isset($pictures[0])) {
                $first = is_array($pictures[0]) ? ($pictures[0]['url'] ?? $pictures[0]['secure_url'] ?? null) : null;
                $imagenUrl = $first;
            }
        }
        if ($imagenUrl !== null && is_string($imagenUrl) && $imagenUrl !== '' && ! str_starts_with($imagenUrl, 'http')) {
            $imagenUrl = 'https://http2.mlstatic.com/D_NQ_NP_' . ltrim((string) $imagenUrl, '/') . '-O.jpg';
        }

        $sku = 'ML-' . ($id ?: substr(md5($nombre), 0, 12));

        return [
            'sku_tienda' => $sku,
            'nombre' => $nombre ?: 'Producto Mercado Libre',
            'precio_original' => round($precioOriginal > 0 ? $precioOriginal : $precio, 2),
            'precio_oferta' => $precio > 0 ? round($precio, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal,
        ];
    }

    /**
     * Rastreo por scraping (página de ofertas HTML). Respaldo cuando la API falla o no hay token.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, origen_rastreo: string}>
     */
    private function rastreoPorScraping(): array
    {
        usleep(random_int(500000, 2000000));

        $headers = HttpRastreador::headersSoloHtml();

        try {
            $resultado = HttpRastreador::getCachedOrFetch(self::URL_OFERTAS, function () use ($headers): array {
                $request = Http::withHeaders($headers)->timeout(60)->connectTimeout(30)
                    ->withOptions(HttpRastreador::opcionesSoloHtmlMl());
                $request = HttpRastreador::conProxySiTexto($request, self::URL_OFERTAS);
                $respuesta = $request->get(self::URL_OFERTAS);

                return ['body' => $respuesta->body(), 'status' => $respuesta->status()];
            }, HttpRastreador::CACHE_PROXY_TTL);
        } catch (\Throwable $e) {
            Log::warning('[Mercado Libre] Error de conexión/proxy (ofertas): ' . $e->getMessage());
            return $this->agregarOrigenScraping($this->intentarFallbackBusqueda($headers));
        }

        if ($resultado['status'] < 200 || $resultado['status'] >= 300) {
            if ($resultado['status'] === 403) {
                Log::info('MercadoLibreMotor: 403 en página ofertas. Intentando fallback de búsqueda.');
            }
            return $this->agregarOrigenScraping($this->intentarFallbackBusqueda($headers));
        }

        $productos = $this->extraerProductosDesdeHtmlOfertas($resultado['body'], self::URL_OFERTAS);
        if (empty($productos)) {
            $productos = $this->extraerProductosDeRespuesta($resultado['body'], self::URL_OFERTAS);
        }

        if (! empty($productos)) {
            $usaProxy = Configuracion::getProxyUrl() !== null;
            Log::info('MercadoLibreMotor: productos extraídos por scraping (página ofertas)' . ($usaProxy ? ' (con proxy)' : ''), [
                'cantidad' => count($productos),
            ]);
            app(EstadoMotorService::class)->reactivar('Mercado Libre');
        } else {
            Log::debug('MercadoLibreMotor: scraping sin productos (HTML de ofertas sin ítems reconocidos).');
        }

        return $this->agregarOrigenScraping($productos);
    }

    /**
     * Añade origen_rastreo = 'Scraping' a cada ítem del array.
     *
     * @param  array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>  $productos
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null, origen_rastreo: string}>
     */
    private function agregarOrigenScraping(array $productos): array
    {
        foreach ($productos as $i => $p) {
            $productos[$i]['origen_rastreo'] = 'Scraping';
        }
        return $productos;
    }

    /**
     * Si ofertas falla, intenta URL de búsqueda segura (/instax) para validar que el túnel está abierto y extraer productos si aplica.
     *
     * @param  array<string, string>  $headers
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    private function intentarFallbackBusqueda(array $headers): array
    {
        try {
            $resultado = HttpRastreador::getCachedOrFetch(self::URL_BUSQUEDA_FALLBACK, function () use ($headers): array {
                $request = Http::withHeaders($headers)->timeout(60)->connectTimeout(30)
                    ->withOptions(HttpRastreador::opcionesSoloHtmlMl());
                $request = HttpRastreador::conProxySiTexto($request, self::URL_BUSQUEDA_FALLBACK);
                $respuesta = $request->get(self::URL_BUSQUEDA_FALLBACK);

                return ['body' => $respuesta->body(), 'status' => $respuesta->status()];
            }, HttpRastreador::CACHE_PROXY_TTL);
        } catch (\Throwable $e) {
            Log::warning('[Mercado Libre] Fallback búsqueda también falló: ' . $e->getMessage());
            return [];
        }

        if ($resultado['status'] >= 200 && $resultado['status'] < 300) {
            Log::info('MercadoLibreMotor: túnel abierto (fallback /instax 200). Extrayendo productos de búsqueda.');
            $productos = $this->extraerProductosDesdeHtmlOfertas($resultado['body'], self::URL_BUSQUEDA_FALLBACK);
            if (empty($productos)) {
                $productos = $this->extraerProductosDeRespuesta($resultado['body'], self::URL_BUSQUEDA_FALLBACK);
            }
            if (! empty($productos)) {
                app(EstadoMotorService::class)->reactivar('Mercado Libre');
                return $productos;
            }
        }

        return [];
    }

    /**
     * Extrae productos desde el HTML de /ofertas con estructura moderna: data-testid="items-list", .carousel_item, .poly-component__title, .andes-money-amount__fraction.
     * URL limpia y con &micosmtics=187001804.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerProductosDesdeHtmlOfertas(string $body, string $urlPagina): array
    {
        $productos = [];
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        if (! @$dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR)) {
            libxml_clear_errors();

            return [];
        }
        $xpath = new DOMXPath($dom);

        // Contenedores: [data-testid="items-list"] .carousel_item, o .promotion-item__container / .poly-card como fallback
        $contenedores = $xpath->query("//*[@data-testid='items-list']//*[contains(@class, 'carousel_item')]");
        if ($contenedores === false || $contenedores->length === 0) {
            $contenedores = $xpath->query("//*[contains(@class, 'promotion-item__container')]");
        }
        if ($contenedores === false || $contenedores->length === 0) {
            $contenedores = $xpath->query("//*[contains(@class, 'poly-card')]");
        }
        if ($contenedores === false || $contenedores->length === 0) {
            libxml_clear_errors();

            return [];
        }

        foreach ($contenedores as $nodo) {
            if (! $nodo instanceof \DOMElement) {
                continue;
            }
            $titulo = $this->extraerTextoXpath($xpath, $nodo, ".//*[contains(@class, 'poly-component__title')]");
            if ($titulo === '') {
                $titulo = $this->extraerTextoXpath($xpath, $nodo, ".//*[contains(@class, 'promotion-item__title')]");
            }
            // Enlace al producto: solo permalink directo (www.mercadolibre.com.mx/.../p/). Rechazar tracking (click1, mclics).
            $enlace = $this->extraerHrefXpath($xpath, $nodo, ".//a[contains(@href, '/p/') and contains(@href, 'www.mercadolibre.com.mx')]");
            if ($enlace === '') {
                $enlace = $this->extraerHrefXpath($xpath, $nodo, ".//a[contains(@href, 'mercadolibre.com.mx/') and not(contains(@href, 'click1.')) and not(contains(@href, 'mclics'))]");
            }
            $enlace = $this->filtrarEnlaceTrackingMl($enlace);
            // Precios desde aria-label ("Antes: X pesos" / "Ahora: X pesos") para evitar mezclar fracciones
            $fracciones = $this->extraerPreciosDesdeAriaLabel($xpath, $nodo);
            // Selectores actualizados: poly-component__picture, poly-card__portada (nuevo diseño ML), data-testid, lazy (data-src/srcset)
            $imagenUrl = $this->extraerImagenXpath($xpath, $nodo, ".//img[contains(@class, 'poly-component__picture')]");
            if ($imagenUrl === '') {
                $imagenUrl = $this->extraerImagenXpath($xpath, $nodo, ".//*[contains(@class, 'poly-card__portada')]//img");
            }
            if ($imagenUrl === '') {
                $imagenUrl = $this->extraerImagenXpath($xpath, $nodo, ".//*[@data-testid='picture']//img");
            }
            if ($imagenUrl === '') {
                $imagenUrl = $this->extraerImagenXpath($xpath, $nodo, ".//img[contains(@src, 'http') or @data-src or @data-srcset or @srcset]");
            }
            if ($imagenUrl === '') {
                $imagenUrl = $this->extraerImagenXpath($xpath, $nodo, ".//img");
            }

            $nombreLog = $titulo ?: 'Producto Mercado Libre';
            if ($imagenUrl !== '') {
                Log::info('ML Motor: Producto ' . $nombreLog . ' - Imagen detectada: ' . $imagenUrl);
            } else {
                Log::info('ML Motor: Producto ' . $nombreLog . ' - Imagen no detectada');
            }

            if ($titulo === '' && $enlace === '') {
                continue;
            }

            $permalink = $enlace !== '' ? (str_starts_with($enlace, 'http') ? $enlace : self::URL_BASE . '/' . ltrim($enlace, '/')) : null;
            if ($permalink !== null) {
                $permalink = HttpRastreador::esEnlaceCortoMercadoLibre($permalink)
                    ? HttpRastreador::expandirUrlCorta($permalink)
                    : $permalink;
            }
            $urlOriginal = $permalink !== null ? self::inyectarAfiliadoEnPermalink($permalink) : null;
            $precioOriginal = $fracciones['original'] ?? 0.0;
            $precioOferta = $fracciones['oferta'] ?? null;
            if ($precioOferta !== null && $precioOriginal <= 0) {
                $precioOriginal = $precioOferta;
            }
            $skuTienda = 'ML-' . substr(md5($titulo ?: $enlace ?: uniqid('', true)), 0, 12);

            $productos[] = [
                'sku_tienda' => $skuTienda,
                'nombre' => $titulo ?: 'Producto Mercado Libre',
                'precio_original' => round($precioOriginal, 2),
                'precio_oferta' => $precioOferta !== null ? round($precioOferta, 2) : null,
                'imagen_url' => $imagenUrl !== '' ? $imagenUrl : null,
                'url_original' => $urlOriginal,
            ];
        }
        libxml_clear_errors();

        return array_slice($productos, 0, 50);
    }

    /**
     * Extrae URL de imagen del primer nodo que coincida. Prueba src, data-src y data-lazy-src (ML usa lazy-load).
     */
    private function extraerImagenXpath(DOMXPath $xpath, \DOMNode $nodo, string $expr): string
    {
        $nodes = $xpath->query($expr, $nodo);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $first = $nodes->item(0);
        if (! $first instanceof \DOMElement) {
            return '';
        }
        $attrs = ['src', 'data-src', 'data-lazy-src', 'data-srcset', 'srcset'];
        foreach ($attrs as $attr) {
            $valor = trim((string) $first->getAttribute($attr));
            if ($valor !== '') {
                if (($attr === 'data-srcset' || $attr === 'srcset') && str_contains($valor, ',')) {
                    $valor = trim(explode(',', $valor)[0]);
                    if (preg_match('/^(\S+)/', $valor, $m)) {
                        $valor = $m[1];
                    }
                }
                if ($valor !== '') {
                    return $this->normalizarUrlImagenMl($valor);
                }
            }
        }
        return '';
    }

    /** Convierte URL relativa o protocol-relative a absoluta para mlstatic.com. */
    private function normalizarUrlImagenMl(string $url): string
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
            return 'https://http2.mlstatic.com' . $url;
        }
        if (! str_contains($url, '://')) {
            return 'https://http2.mlstatic.com/' . ltrim($url, '/');
        }
        return $url;
    }

    private function extraerTextoXpath(DOMXPath $xpath, \DOMNode $nodo, string $expr): string
    {
        $nodes = $xpath->query($expr, $nodo);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $texto = $nodes->item(0)->textContent ?? '';

        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    private function extraerHrefXpath(DOMXPath $xpath, \DOMNode $nodo, string $expr): string
    {
        $nodes = $xpath->query($expr, $nodo);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $first = $nodes->item(0);
        $href = $first instanceof \DOMElement ? $first->getAttribute('href') : '';

        return trim($href);
    }

    /** Rechaza enlaces de tracking ML (click1, mclics); devuelve vacío para no guardar URL de redirección. */
    private function filtrarEnlaceTrackingMl(string $enlace): string
    {
        if ($enlace === '') {
            return '';
        }
        if (str_contains($enlace, 'click1.mercadolibre') || str_contains($enlace, 'mclics')) {
            return '';
        }

        return $enlace;
    }

    /**
     * Extrae precios desde aria-label de ML: "Antes: X pesos" = original, "Ahora: X pesos" = oferta.
     * Soporta "con N centavos" para decimales. Evita mezclar fracciones de varios bloques.
     *
     * @return array{original: float, oferta: float|null}
     */
    private function extraerPreciosDesdeAriaLabel(DOMXPath $xpath, \DOMNode $nodo): array
    {
        $original = 0.0;
        $oferta = null;
        $antes = $xpath->query(".//*[@role='img' and starts-with(normalize-space(@aria-label), 'Antes:')]", $nodo);
        if ($antes !== false && $antes->length > 0) {
            $el = $antes->item(0);
            $label = $el instanceof \DOMElement ? trim($el->getAttribute('aria-label') ?? '') : '';
            $original = $this->parsearPrecioDesdeAriaLabel($label);
        }
        $ahora = $xpath->query(".//*[@role='img' and starts-with(normalize-space(@aria-label), 'Ahora:')]", $nodo);
        if ($ahora !== false && $ahora->length > 0) {
            $el = $ahora->item(0);
            $label = $el instanceof \DOMElement ? trim($el->getAttribute('aria-label') ?? '') : '';
            $oferta = $this->parsearPrecioDesdeAriaLabel($label);
            if ($oferta <= 0) {
                $oferta = null;
            }
        }
        if ($original <= 0 && $oferta !== null) {
            $original = $oferta;
        }

        return ['original' => $original, 'oferta' => $oferta];
    }

    /**
     * Parsea un valor de precio desde aria-label tipo "Antes: 2199 pesos" o "Ahora: 298 pesos con 90 centavos".
     */
    private function parsearPrecioDesdeAriaLabel(string $ariaLabel): float
    {
        if ($ariaLabel === '') {
            return 0.0;
        }
        $entero = 0;
        if (preg_match('/(\d+)\s+pesos/ui', $ariaLabel, $m)) {
            $entero = (int) $m[1];
        }
        $centavos = 0;
        if (preg_match('/con\s+(\d+)\s+centavos/ui', $ariaLabel, $m2)) {
            $centavos = (int) $m2[1];
        }

        return $entero + $centavos / 100;
    }

    /**
     * Comisión de afiliado: micosmtics=187001804 (o ID configurado) se mantiene siempre al final de la URL.
     * Nunca se borra; se añade o reemplaza y se coloca al final para blindaje de comisión.
     */
    private static function inyectarAfiliadoEnPermalink(string $permalink): string
    {
        $idAfiliado = Configuracion::getMlAffiliateId() ?: self::ID_AFILIADO_FALLBACK;
        $idAfiliado = $idAfiliado !== '' ? $idAfiliado : self::ID_AFILIADO_FALLBACK;

        $parsed = parse_url($permalink);
        if (! is_array($parsed) || empty($parsed['host'])) {
            return self::anadirOReemplazarMicosmtics($permalink, $idAfiliado);
        }

        $base = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'] . ($parsed['path'] ?? '/');
        $base = rtrim($base, '/');
        $query = $parsed['query'] ?? '';
        parse_str($query, $params);
        unset($params['micosmtics']);
        $suffix = 'micosmtics=' . $idAfiliado;
        $queryResto = http_build_query($params);
        if ($queryResto !== '') {
            return $base . '?' . $queryResto . '&' . $suffix;
        }

        return $base . '?' . $suffix;
    }

    /**
     * Fallback cuando no hay host: añade o reemplaza micosmtics y lo deja al final. Nunca elimina micosmtics.
     */
    private static function anadirOReemplazarMicosmtics(string $url, string $idAfiliado): string
    {
        $suffix = 'micosmtics=' . $idAfiliado;
        if (str_contains($url, 'micosmtics=')) {
            $url = preg_replace('/[?&]micosmtics=[^&]*/', '', $url) ?? $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . $suffix;
    }

    /**
     * Extrae productos desde el HTML de la página (fallback cuando la API no responde).
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
            Log::debug('MercadoLibreMotor: no se extrajeron productos desde HTML (posible bloqueo o cambio de estructura).');
        }

        return $productos;
    }

    /**
     * Mapea ítems desde __NEXT_DATA__ del HTML.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdeNextData(array $data): array
    {
        $items = $data['props']['pageProps']['initialState']['results']['results'] ?? $data['props']['pageProps']['results'] ?? [];
        if (! is_array($items)) {
            return [];
        }
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            $m = $this->normalizarItem($item);
            if ($m !== null) {
                $productos[] = $m;
            }
        }

        return $productos;
    }

    /**
     * Normaliza un ítem del HTML/Next data (mismo formato que API; enlaces con micosmtics).
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItem(array $item): ?array
    {
        $id = (string) ($item['id'] ?? $item['item_id'] ?? '');
        $nombre = (string) ($item['title'] ?? $item['name'] ?? '');
        if ($id === '' && $nombre === '') {
            return null;
        }
        $sku = 'ML-' . ($id ?: substr(md5($nombre), 0, 12));
        $precio = (float) ($item['price'] ?? $item['original_price'] ?? 0);
        $precioOferta = (float) ($item['sale_price'] ?? $item['price'] ?? 0);
        if ($precioOferta <= 0) {
            $precioOferta = $precio;
        }
        $imagenUrl = $item['thumbnail'] ?? $item['picture'] ?? $item['thumbnail_url'] ?? $item['image'] ?? null;
        $imagenUrl = is_string($imagenUrl) && $imagenUrl !== '' ? $this->normalizarUrlImagenMl($imagenUrl) : null;
        $permalink = $item['permalink'] ?? $item['url'] ?? null;
        if (is_string($permalink) && ! str_starts_with($permalink, 'http')) {
            $permalink = self::URL_BASE . '/' . ltrim($permalink, '/');
        }
        if (is_string($permalink) && $permalink !== '' && HttpRastreador::esEnlaceCortoMercadoLibre($permalink)) {
            $permalink = HttpRastreador::expandirUrlCorta($permalink);
        }
        $urlOriginal = $permalink ? self::inyectarAfiliadoEnPermalink((string) $permalink) : null;
        $nombreLog = $nombre ?: 'Producto Mercado Libre';
        if ($imagenUrl !== null && $imagenUrl !== '') {
            Log::info('ML Motor: Producto ' . $nombreLog . ' - Imagen detectada: ' . $imagenUrl);
        } else {
            Log::info('ML Motor: Producto ' . $nombreLog . ' - Imagen no detectada');
        }

        return [
            'sku_tienda' => $sku,
            'nombre' => $nombre ?: 'Producto Mercado Libre',
            'precio_original' => round($precio > 0 ? $precio : $precioOferta, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal,
        ];
    }
}
