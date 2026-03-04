<?php

namespace App\Motores;

use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Sam's Club México.
 * Usa Guzzle vía BaseMotorRastreador.
 */
class SamsClubMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.sams.com.mx';

    /** Ofertas exclusivas (categoría cat1250005). */
    protected const RUTA_OFERTAS = 'c/ofertas-exclusivas/cat1250005';

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
            $this->registrarRespuestaParaDebug($body, $urlPagina);
        }

        return $productos;
    }

    /**
     * Cuando la extracción falla, registra en el log qué clase de HTML se recibió para ajustar selectores.
     */
    protected function registrarRespuestaParaDebug(string $body, string $urlPagina): void
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
