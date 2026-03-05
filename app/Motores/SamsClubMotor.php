<?php

namespace App\Motores;

use App\Support\HttpRastreador;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Sam's Club México.
 * Estrategia: consumo de API interna (showcase) en lugar de HTML que depende de JS.
 */
class SamsClubMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.sams.com.mx';

    /** API de búsqueda rápida (JSON). */
    protected const API_SHOWCASE = '/api/v1/search/showcase';

    /** Búsqueda directa de rebajas (fallback si la API no devuelve datos). */
    protected const RUTA_OFERTAS = 's/rebajas';

    /** Ofertas exclusivas sin ID final por si rebajas falla. */
    protected const RUTA_OFERTAS_ALT = 'c/ofertas-exclusivas';

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
     * Para peticiones a la API se añaden headers de validación (evitar bloqueo/Captcha).
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
                'Origin' => 'https://www.sams.com.mx',
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
     * Primero intenta la API de búsqueda showcase (JSON); si falla o no hay datos, fallback a HTML.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $this->peticionesRealizadas = 0;
        $base = rtrim($this->getUrlBase(), '/');

        $homeUrl = $base . '/';
        $this->realizarPeticion($homeUrl);

        $urlApi = $base . self::API_SHOWCASE . '?searchString=ofertas';
        $resultadoApi = $this->realizarPeticion($urlApi);

        if ($resultadoApi !== null && ($resultadoApi['status'] === 401 || $resultadoApi['status'] === 403)) {
            Log::error('API Bloqueada', ['cuerpo' => $resultadoApi['body'], 'url' => $urlApi, 'status' => $resultadoApi['status']]);
        }

        if ($resultadoApi !== null && $resultadoApi['status'] === 200) {
            $body = $resultadoApi['body'];
            $data = json_decode($body, true);
            if (! is_array($data) && $body !== '') {
                Log::warning('SamsClubMotor: respuesta API no es JSON (posible Captcha).', [
                    'inicio_respuesta' => mb_substr($body, 0, 500),
                    'url' => $urlApi,
                ]);
            }
            $productos = $this->extraerDesdeApiShowcase($body);
            if (! empty($productos)) {
                Log::info(static::class . ': productos obtenidos vía API showcase', ['total' => count($productos)]);
                return $productos;
            }
        }

        $urlOfertas = $base . '/' . ltrim($this->getRutaOfertas(), '/');
        $resultado = $this->realizarPeticion($urlOfertas);
        if ($resultado === null) {
            Log::info(static::class . ': sin respuesta ofertas', ['url' => $urlOfertas]);
            return [];
        }

        $productos = $this->extraerProductosDeRespuesta($resultado['body'], $urlOfertas);
        if (empty($productos) && $resultado['status'] === 404) {
            $urlAlt = $base . '/' . ltrim(self::RUTA_OFERTAS_ALT, '/');
            $resultadoAlt = $this->realizarPeticion($urlAlt);
            if ($resultadoAlt !== null && $resultadoAlt['status'] === 200) {
                $productos = $this->extraerProductosDeRespuesta($resultadoAlt['body'], $urlAlt);
            }
        } elseif (empty($productos) && $resultado['status'] === 200) {
            $urlAlt = $base . '/' . ltrim(self::RUTA_OFERTAS_ALT, '/');
            $resultadoAlt = $this->realizarPeticion($urlAlt);
            if ($resultadoAlt !== null && $resultadoAlt['status'] === 200) {
                $productos = $this->extraerProductosDeRespuesta($resultadoAlt['body'], $urlAlt);
            }
        }
        if (empty($productos)) {
            $this->registrarRespuestaParaDebug($resultado['body'], $urlOfertas, $resultado['status']);
        }
        return $productos;
    }

    /**
     * Extrae productos del JSON de la API showcase (searchString=ofertas).
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerDesdeApiShowcase(string $body): array
    {
        $data = json_decode($body, true);
        if (! is_array($data)) {
            return [];
        }
        $items = $data['products'] ?? $data['items'] ?? $data['results'] ?? $data['data'] ?? $data['productSummaries'] ?? [];
        if (! is_array($items)) {
            return [];
        }
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            $m = $this->normalizarItemApiShowcase($item);
            if ($m !== null) {
                $productos[] = $m;
            }
        }
        return $productos;
    }

    /**
     * Mapea un ítem de la API showcase al formato interno.
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItemApiShowcase(array $item): ?array
    {
        $nombre = (string) ($item['productName'] ?? $item['name'] ?? $item['title'] ?? $item['displayName'] ?? '');
        if ($nombre === '') {
            return null;
        }
        $sku = (string) ($item['sku'] ?? $item['productId'] ?? $item['id'] ?? $item['itemId'] ?? '');
        $skuTienda = 'SAM-' . ($sku ?: substr(md5($nombre), 0, 12));

        $precioOferta = (float) ($item['price'] ?? $item['salePrice'] ?? $item['currentPrice'] ?? 0);
        $precioOriginal = (float) ($item['listPrice'] ?? $item['regularPrice'] ?? $item['originalPrice'] ?? $precioOferta);
        if ($precioOriginal <= 0) {
            $precioOriginal = $precioOferta;
        }
        if ($precioOferta <= 0 && $precioOriginal <= 0) {
            return null;
        }

        $imagenUrl = $item['image'] ?? $item['imageUrl'] ?? $item['thumbnail'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? null;
        }
        $urlOriginal = $this->normalizarUrlPublicaSams($item['url'] ?? $item['link'] ?? $item['productUrl'] ?? null);

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Sam\'s Club',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 && $precioOferta < $precioOriginal ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal,
        ];
    }

    /**
     * Asegura que la URL apunte a la tienda pública (sams.com.mx), no a APIs internas.
     */
    protected function normalizarUrlPublicaSams(?string $url): ?string
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
        return str_starts_with($url, 'https://www.sams.com.mx') ? $url : null;
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
                $productos = $this->mapearDesdeNextData($json);
            }
        }
        return $productos;
    }

    /**
     * Cuando la extracción falla, registra en el log qué clase de HTML se recibió para ajustar selectores.
     */
    protected function registrarRespuestaParaDebug(string $body, string $urlPagina, int $status = 0): void
    {
        $longitud = strlen($body);
        $tieneNextData = str_contains($body, '__NEXT_DATA__');
        $titulo = '';
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $body, $m)) {
            $titulo = trim(strip_tags($m[1]));
        }
        $inicio = mb_substr($body, 0, 800);
        Log::warning('SamsClubMotor: extracción fallida. Respuesta para ajustar selectores.', [
            'url' => $urlPagina,
            'status' => $status,
            'interpretacion' => $status === 403 ? 'posible bloqueo (403)' : ($status !== 200 ? 'respuesta no OK' : 'cambio de estructura'),
            'longitud_body' => $longitud,
            'tiene___NEXT_DATA__' => $tieneNextData,
            'titulo_pagina' => $titulo,
            'inicio_html' => $inicio,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearDesdeNextData(array $data): array
    {
        $items = $data['props']['pageProps']['products'] ?? $data['props']['pageProps']['items'] ?? [];
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
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItem(array $item): ?array
    {
        $sku = (string) ($item['sku'] ?? $item['productId'] ?? $item['id'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['title'] ?? '');
        if ($sku === '' && $nombre === '') {
            return null;
        }
        $skuTienda = 'SAM-' . ($sku ?: substr(md5($nombre), 0, 12));
        $precioOriginal = (float) ($item['listPrice'] ?? $item['regularPrice'] ?? 0);
        $precioOferta = (float) ($item['salePrice'] ?? $item['price'] ?? 0);
        if ($precioOriginal <= 0) {
            $precioOriginal = $precioOferta;
        }
        $imagenUrl = $item['image'] ?? $item['imageUrl'] ?? null;
        if (is_array($imagenUrl)) {
            $imagenUrl = $imagenUrl['url'] ?? $imagenUrl[0] ?? null;
        }
        $urlOriginal = $this->normalizarUrlPublicaSams($item['url'] ?? $item['link'] ?? null);

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Sam\'s Club',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal,
        ];
    }
}
