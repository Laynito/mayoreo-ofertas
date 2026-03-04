<?php

namespace App\Motores;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Sam's Club México.
 * Usa Guzzle vía BaseMotorRastreador. Pide primero la Home (/) para obtener cookies de sesión.
 */
class SamsClubMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.sams.com.mx';

    /** Búsqueda por palabra "ofertas" (evita 404 por catid obsoleto). */
    protected const RUTA_OFERTAS = 's/ofertas';

    /** Rebajas sin ID de categoría por si la búsqueda falla. */
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

    protected function getClaveConfigProxy(): ?string
    {
        return 'sams_club';
    }

    /**
     * Cliente con CookieJar para persistir cookies de la Home y enviarlas en ofertas.
     */
    protected function configurarCliente(): Client
    {
        if ($this->cliente !== null) {
            return $this->cliente;
        }
        $this->cookieJar = new CookieJar;
        $urlBase = $this->getUrlBase();
        $opciones = [
            'timeout' => 15,
            'connect_timeout' => 10,
            'allow_redirects' => true,
            'cookies' => $this->cookieJar,
            'headers' => $this->obtenerCabecerasNavegador($urlBase),
        ];
        $proxy = config('services.sams_club.proxy');
        if (! empty($proxy)) {
            $opciones['proxy'] = $proxy;
        }
        $this->cliente = new Client($opciones);
        return $this->cliente;
    }

    /**
     * Primero pide la Home (/) para obtener cookies; luego la página de ofertas.
     * Si la URL principal no devuelve productos, prueba la URL alternativa.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $this->peticionesRealizadas = 0;
        $base = rtrim($this->getUrlBase(), '/');
        $this->configurarCliente();

        $homeUrl = $base . '/';
        $resultadoHome = $this->realizarPeticion($homeUrl);
        if ($resultadoHome === null) {
            Log::info(static::class . ': fallo petición a Home', ['url' => $homeUrl]);
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
        $urlOriginal = $item['url'] ?? $item['link'] ?? null;
        if (is_string($urlOriginal) && ! str_starts_with($urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim($urlOriginal, '/');
        }

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Sam\'s Club',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
    }
}
