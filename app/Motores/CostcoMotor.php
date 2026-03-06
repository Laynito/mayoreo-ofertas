<?php

namespace App\Motores;

use App\Support\HttpRastreador;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Costco México.
 * Estrategia: intentar API de búsqueda primero (verificar URL en network log del navegador); fallback a HTML.
 */
class CostcoMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.costco.com.mx';

    /**
     * Posible endpoint de búsqueda (confirmar en pestaña Network al cargar ofertas).
     * Si devuelve 404 o no JSON, se usa el fallback HTML.
     */
    protected const API_SEARCH = '/api/v1/search';

    /** Sección de liquidaciones/ofertas (sin barra final). */
    protected const RUTA_OFERTAS = 'c/ofertas';

    protected const RUTA_OFERTAS_ALT = 'treasure-hunt';

    protected ?CookieJar $cookieJar = null;

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Headers de validación para peticiones a la API (evitar bloqueo/Captcha).
     *
     * @return array<string, mixed>
     */
    protected function getOpcionesPeticion(string $url): array
    {
        if (! str_contains($url, '/api/')) {
            return [];
        }
        return [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0 Safari/537.36',
                'X-Requested-With' => 'XMLHttpRequest',
                'Origin' => 'https://www.costco.com.mx',
            ],
        ];
    }

    /**
     * Peticiones con CookieJar para persistir sesión (Home + ofertas). Usa Http de Laravel y PROXY_URL si está definida.
     *
     * @return array{body: string, status: int}|null
     */
    protected function realizarPeticion(string $url): ?array
    {
        $this->pausarEntrePeticiones();

        if ($this->cookieJar === null) {
            $this->cookieJar = new CookieJar;
        }

        $cabeceras = $this->obtenerCabecerasNavegador($this->getUrlBase());
        $opciones = $this->getOpcionesPeticion($url);
        if (isset($opciones['headers']) && is_array($opciones['headers'])) {
            $cabeceras = array_merge($cabeceras, $opciones['headers']);
        }

        $request = Http::withHeaders($cabeceras)
            ->withOptions(['cookies' => $this->cookieJar])
            ->timeout(15)
            ->connectTimeout(10);
        $request = HttpRastreador::conProxySiTexto($request, $url);

        try {
            $respuesta = $request->get($url);
            $this->peticionesRealizadas++;

            return [
                'body' => $respuesta->body(),
                'status' => $respuesta->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning(static::class . ': error en petición', ['url' => $url, 'mensaje' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Primero intenta la API de búsqueda; si no hay JSON válido o no hay datos, fallback a página de ofertas (HTML).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $this->peticionesRealizadas = 0;
        $base = rtrim($this->getUrlBase(), '/');

        $homeUrl = $base . '/';
        $this->realizarPeticion($homeUrl);

        $urlApi = $base . self::API_SEARCH . '?keyword=ofertas';
        $resultadoApi = $this->realizarPeticion($urlApi);

        if ($resultadoApi !== null && ($resultadoApi['status'] === 401 || $resultadoApi['status'] === 403)) {
            Log::error('API Bloqueada', ['cuerpo' => $resultadoApi['body'], 'url' => $urlApi, 'status' => $resultadoApi['status']]);
        }

        if ($resultadoApi !== null && $resultadoApi['status'] === 200) {
            $body = $resultadoApi['body'];
            $data = json_decode($body, true);
            if (! is_array($data) && $body !== '') {
                Log::warning('CostcoMotor: respuesta API no es JSON (posible Captcha).', [
                    'inicio_respuesta' => mb_substr($body, 0, 500),
                    'url' => $urlApi,
                ]);
            }
            if (is_array($data)) {
                $productos = $this->extraerDesdeApiSearch($data);
                if (! empty($productos)) {
                    Log::info(static::class . ': productos obtenidos vía API', ['total' => count($productos)]);
                    return $productos;
                }
            }
        }

        $urlOfertas = $base . '/' . ltrim($this->getRutaOfertas(), '/');
        $resultado = $this->realizarPeticion($urlOfertas);
        if ($resultado === null) {
            Log::info(static::class . ': sin respuesta ofertas', ['url' => $urlOfertas]);
            return [];
        }
        if ($resultado['status'] === 404) {
            $urlAlt = $base . '/' . ltrim(self::RUTA_OFERTAS_ALT, '/');
            $resultado = $this->realizarPeticion($urlAlt);
            if ($resultado === null || $resultado['status'] !== 200) {
                Log::info(static::class . ': 404 en c/ofertas y fallo en alternate', ['url_alt' => $urlAlt]);
                return [];
            }
            $urlOfertas = $urlAlt;
        } elseif ($resultado['status'] !== 200) {
            Log::info(static::class . ': respuesta no 200', ['url' => $urlOfertas, 'status' => $resultado['status']]);
        }

        $productos = $this->extraerProductosDeRespuesta($resultado['body'], $urlOfertas);
        if (empty($productos)) {
            Log::warning('CostcoMotor: no se extrajeron productos.', [
                'url' => $urlOfertas,
                'status' => $resultado['status'],
                'interpretacion' => $resultado['status'] === 403 ? 'posible bloqueo (403)' : 'cambio de estructura',
            ]);
        }
        return $productos;
    }

    /**
     * Extrae productos del JSON de la API de búsqueda (estructura a confirmar con network log).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeApiSearch(array $data): array
    {
        $items = $data['products'] ?? $data['items'] ?? $data['results'] ?? $data['data'] ?? [];
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
        return $productos;
    }

    /**
     * Extrae lista de productos desde __NEXT_DATA__. Prueba varias rutas (Costco puede cambiar estructura).
     *
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
            Log::debug('CostcoMotor: __NEXT_DATA__ sin productos; claves pageProps', [
                'url' => $urlPagina,
                'pageProps_keys' => is_array($props) ? array_keys($props) : [],
            ]);
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
     * Busca recursivamente un array de productos/items en la estructura JSON (máximo 4 niveles).
     *
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
     * Fallback: extracción desde window.__PRELOADED_STATE__ si existe.
     *
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
     * Normaliza un ítem de producto (API o __NEXT_DATA__). Acepta variantes de nombres de campo.
     *
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

    /**
     * Asegura que la URL apunte a la tienda pública (costco.com.mx), no a APIs internas.
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
}
