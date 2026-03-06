<?php

namespace App\Motores;

use App\Services\EstadoMotorService;
use App\Support\HttpRastreador;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Motor de rastreo para Mercado Libre México (API pública MLM).
 *
 * Reglas finales:
 * - Búsqueda anónima: peticiones a la API de promociones a través del proxy SIN AccessToken (petición pública) para evitar bloqueos.
 * - Comisión de afiliado: ID 187001804; se inyecta &micosmtics=187001804 en cada enlace guardado (ML_AFFILIATE_ID en .env).
 * - Restricción de producto: si en Filament el producto tiene "Permitir descuento adicional" desactivado, el bot no modifica su precio ni aplica rebajas automáticas (lógica en RastreoTiendaComando y CalculadoraOfertas).
 * - Código y comentarios en español.
 */
class MercadoLibreMotor extends BaseMotorRastreador
{
    protected const URL_BASE = 'https://www.mercadolibre.com.mx';

    protected const RUTA_OFERTAS = 'ofertas';

    /** Endpoint de promociones (site_id=MLM en query, no en la ruta). */
    private const API_PROMOCIONES = 'https://api.mercadolibre.com/promotions/search';

    protected function getUrlBase(): string
    {
        return self::URL_BASE;
    }

    protected function getRutaOfertas(): string
    {
        return self::RUTA_OFERTAS;
    }

    /**
     * Cabeceras para petición pública (búsqueda anónima; sin token ni App ID).
     * User-Agent Chrome y Referer para que la API no bloquee con proxy.
     *
     * @return array<string, string>
     */
    private function obtenerHeadersSoloML(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Accept-Language' => 'es-MX,es;q=0.9',
            'Referer' => 'https://www.mercadolibre.com.mx/',
        ];
    }

    /**
     * Recolecta primero vía API MLM (promociones); si falla, intenta HTML.
     * La restricción permite_descuento_adicional se aplica al encolar/notificar, no aquí.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $productos = $this->recolectarDesdeApi();
        if (! empty($productos)) {
            $usaProxy = config('services.proxy_url') !== null && config('services.proxy_url') !== '';
            Log::info('MercadoLibreMotor: productos extraídos vía API MLM' . ($usaProxy ? ' (con proxy)' : ''), [
                'cantidad' => count($productos),
                'con_proxy' => $usaProxy,
            ]);
            // Respuesta exitosa (200 OK): asegurar que el motor no quede marcado como bloqueado por el sistema de salud.
            app(EstadoMotorService::class)->reactivar('Mercado Libre');
            return $productos;
        }

        return parent::recolectarDatos();
    }

    /**
     * Búsqueda anónima: petición 100% pública a /promotions/search a través del proxy, sin AccessToken.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    protected function recolectarDesdeApi(): array
    {
        $url = self::API_PROMOCIONES . '?site_id=MLM&type=ALL&limit=50';
        $proxyActivo = config('services.proxy_url') !== null && config('services.proxy_url') !== '';
        $headers = $proxyActivo ? $this->obtenerHeadersSoloML() : array_merge(HttpRastreador::headersNavegador(), $this->obtenerHeadersSoloML());

        $request = Http::withHeaders($headers)->timeout(60)->connectTimeout(30);
        $request = HttpRastreador::conProxySiTexto($request, $url);
        $respuesta = $request->get($url);

        if (! $respuesta->successful()) {
            Log::debug('MercadoLibreMotor: API no exitosa', ['status' => $respuesta->status()]);
            if ($respuesta->status() === 403) {
                Log::info('MercadoLibreMotor: 403 (tengine/PolicyAgent). Rotar sesión del proxy en PROXY_URL (ej. otro session-XXX).');
            }
            return [];
        }

        $data = $respuesta->json();
        $resultados = $data['results'] ?? [];
        if (! is_array($resultados)) {
            return [];
        }

        $productos = [];
        foreach (array_slice($resultados, 0, 50) as $item) {
            $normalizado = $this->normalizarItemApi($item);
            if ($normalizado !== null) {
                $productos[] = $normalizado;
            }
        }

        return $productos;
    }

    /**
     * Mapeo de ítem API: título, precio original, precio oferta, thumbnail, permalink con micosmtics.
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}|null
     */
    protected function normalizarItemApi(array $item): ?array
    {
        $id = (string) ($item['id'] ?? '');
        $nombre = (string) ($item['title'] ?? '');
        if ($id === '' && $nombre === '') {
            return null;
        }
        $skuTienda = 'ML-' . ($id ?: substr(md5($nombre), 0, 12));
        $precioOriginal = (float) ($item['original_price'] ?? $item['price'] ?? 0);
        $precioOferta = (float) ($item['price'] ?? 0);
        if ($precioOriginal <= 0) {
            $precioOriginal = $precioOferta;
        }
        $imagenUrl = $item['thumbnail'] ?? $item['thumbnail_id'] ?? null;
        if ($imagenUrl !== null && ! is_string($imagenUrl)) {
            $imagenUrl = null;
        }
        $permalink = $item['permalink'] ?? null;
        if ($permalink !== null && ! str_starts_with((string) $permalink, 'http')) {
            $permalink = self::URL_BASE . '/' . ltrim((string) $permalink, '/');
        }
        $urlOriginal = $permalink ? self::inyectarAfiliadoEnPermalink((string) $permalink) : null;

        return [
            'sku_tienda' => $skuTienda,
            'nombre' => $nombre ?: 'Producto Mercado Libre',
            'precio_original' => round($precioOriginal, 2),
            'precio_oferta' => $precioOferta > 0 && $precioOferta < $precioOriginal ? round($precioOferta, 2) : null,
            'imagen_url' => $imagenUrl ? (string) $imagenUrl : null,
            'url_original' => $urlOriginal,
        ];
    }

    /**
     * Comisión de afiliado: inyecta &micosmtics=187001804 en cada enlace (ID desde ML_AFFILIATE_ID o 187001804 por defecto).
     */
    private static function inyectarAfiliadoEnPermalink(string $permalink): string
    {
        $idAfiliado = config('services.mercado_libre.affiliate_id') ?: '187001804';
        if ($idAfiliado === '') {
            return $permalink;
        }

        return $permalink . (str_contains($permalink, '?') ? '&' : '?') . 'micosmtics=' . $idAfiliado;
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
        $imagenUrl = $item['thumbnail'] ?? $item['picture'] ?? null;
        $permalink = $item['permalink'] ?? $item['url'] ?? null;
        if (is_string($permalink) && ! str_starts_with($permalink, 'http')) {
            $permalink = self::URL_BASE . '/' . ltrim($permalink, '/');
        }
        $urlOriginal = $permalink ? self::inyectarAfiliadoEnPermalink((string) $permalink) : null;

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
