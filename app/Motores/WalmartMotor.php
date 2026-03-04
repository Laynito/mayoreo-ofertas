<?php

namespace App\Motores;

use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Walmart México.
 * Usa BaseMotorRastreador: configurarCliente() con Guzzle, Chrome 131, config('services.walmart.proxy').
 * Extracción inteligente: solo scripts JSON (__NEXT_DATA__, __PRELOADED_STATE__), sin HTML puro (más estable).
 */
class WalmartMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.walmart.com.mx';

    /** Ruta más robusta: búsqueda "ofertas" (evita 404 de /super/ofertas). */
    protected const RUTA_OFERTAS = 'search?q=ofertas';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Proxy: config('services.walmart.proxy') o env('WALMART_HTTP_PROXY').
     */
    protected function getClaveConfigProxy(): ?string
    {
        return 'walmart';
    }

    /**
     * Extracción inteligente: busca primero en scripts JSON del HTML (más estable que parsear HTML puro).
     * Orden: 1) __NEXT_DATA__, 2) window.__PRELOADED_STATE__. No se intenta parseo de HTML puro.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function extraerProductosDeRespuesta(string $body, string $urlPagina): array
    {
        $productos = [];

        if (preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.+?)<\/script>/s', $body, $coincidencias)) {
            $json = json_decode(trim($coincidencias[1]), true);
            if (is_array($json)) {
                $productos = $this->mapearProductosDesdeNextData($json);
            }
        }

        if (empty($productos) && preg_match('/window\.__PRELOADED_STATE__\s*=\s*(\{.+\});/s', $body, $coincidencias)) {
            $json = json_decode($coincidencias[1], true);
            if (is_array($json)) {
                $productos = $this->mapearProductosDesdePreloadedState($json);
            }
        }

        if (empty($productos)) {
            $this->registrarRespuestaParaDebug($body, $urlPagina, 'WalmartMotor');
        }

        return $productos;
    }

    /**
     * Cuando la extracción falla, registra en el log qué clase de HTML se recibió para ajustar selectores.
     */
    protected function registrarRespuestaParaDebug(string $body, string $urlPagina, string $motor): void
    {
        $longitud = strlen($body);
        $tieneNextData = str_contains($body, '__NEXT_DATA__');
        $tienePreloadedState = str_contains($body, '__PRELOADED_STATE__');
        $titulo = '';
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $body, $m)) {
            $titulo = trim(strip_tags($m[1]));
        }
        $inicio = mb_substr($body, 0, 800);

        Log::warning("{$motor}: extracción fallida. Respuesta para ajustar selectores.", [
            'url' => $urlPagina,
            'longitud_body' => $longitud,
            'tiene___NEXT_DATA__' => $tieneNextData,
            'tiene___PRELOADED_STATE__' => $tienePreloadedState,
            'titulo_pagina' => $titulo,
            'inicio_html' => $inicio,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearProductosDesdeNextData(array $data): array
    {
        $props = $data['props']['pageProps'] ?? $data['props'] ?? [];
        $items = $props['initialData']['searchResult']['itemStacks'][0]['items'] ?? $props['products'] ?? [];

        return $this->mapearItems($items);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearProductosDesdePreloadedState(array $data): array
    {
        $items = $data['products'] ?? $data['search']['items'] ?? $data['itemStacks'][0]['items'] ?? [];

        return $this->mapearItems($items);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function mapearItems(array $items): array
    {
        $productos = [];
        foreach (array_slice($items, 0, 50) as $item) {
            $mapeado = $this->normalizarItemWalmart($item);
            if ($mapeado !== null) {
                $productos[] = $mapeado;
            }
        }

        return $productos;
    }

    /**
     * Normaliza un ítem crudo al formato esperado por RastrearTienda y columnas de Producto.
     * Claves de salida: sku_tienda, nombre, precio_original, precio_oferta, imagen_url, url_original
     * (el comando añade tienda_origen, porcentaje_ahorro, url_afiliado, ultima_actualizacion_precio, etc.).
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItemWalmart(array $item): ?array
    {
        $sku = (string) ($item['id'] ?? $item['sku'] ?? $item['productId'] ?? '');
        $nombre = (string) ($item['name'] ?? $item['title'] ?? $item['nombre'] ?? '');

        if ($sku === '' || $nombre === '') {
            return null;
        }

        $skuTienda = 'WAL-' . $sku;

        $precioOriginal = isset($item['listPrice']) ? (float) $item['listPrice'] : (float) ($item['price'] ?? 0);
        $precioOferta = null;
        if (isset($item['currentPrice']) && (float) $item['currentPrice'] > 0) {
            $precioOferta = (float) $item['currentPrice'];
        } elseif (isset($item['salePrice'])) {
            $precioOferta = (float) $item['salePrice'];
        }
        if ($precioOriginal <= 0 && $precioOferta !== null) {
            $precioOriginal = $precioOferta;
        }

        $imagenUrl = null;
        if (! empty($item['image'])) {
            $imagenUrl = is_string($item['image']) ? $item['image'] : ($item['image']['url'] ?? null);
        }
        $imagenUrl = $imagenUrl ?? $item['thumbnailUrl'] ?? $item['imageUrl'] ?? null;
        if (is_string($imagenUrl) && str_starts_with($imagenUrl, '//')) {
            $imagenUrl = 'https:' . $imagenUrl;
        }

        $urlOriginal = $item['url'] ?? $item['productUrl'] ?? null;
        if (is_string($urlOriginal) && ! str_starts_with($urlOriginal, 'http')) {
            $urlOriginal = self::URL_BASE . '/' . ltrim($urlOriginal, '/');
        }

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre,
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta !== null ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
    }
}
