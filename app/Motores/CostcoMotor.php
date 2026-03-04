<?php

namespace App\Motores;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Costco México.
 * Usa Guzzle vía BaseMotorRastreador. Pide primero la Home (/) para obtener cookies de sesión.
 */
class CostcoMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.costco.com.mx';

    /** Ruta actual de ofertas (404 en /ofertas). */
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

    protected function getClaveConfigProxy(): ?string
    {
        return 'costco';
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
        $proxy = config('services.costco.proxy');
        if (! empty($proxy)) {
            $opciones['proxy'] = $proxy;
        }
        $this->cliente = new Client($opciones);
        return $this->cliente;
    }

    /**
     * Primero pide la Home (/) para obtener cookies; luego la página de ofertas.
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
        $skuTienda = 'COS-' . ($sku ?: substr(md5($nombre), 0, 12));
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
            'nombre' => $nombre ?: 'Producto Costco',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal ? (string) $urlOriginal : null,
        ];
    }
}
