<?php

namespace App\Services;

use App\Http\Integrations\MercadoLibre\MercadoLibreConnector;
use App\Http\Integrations\MercadoLibre\Requests\GetAffiliateLinkRequest;
use App\Models\Marketplace;
use App\Models\Producto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AffiliateService
{
    /**
     * Genera el link de afiliado para una URL de producto usando la API de Mercado Libre (Saloon).
     * La URL se acorta antes (solo dominio + ID de producto) para enlaces más limpios.
     * Failsafe: si la API falla, devuelve link manual con matt_tool = tu App ID de afiliación.
     */
    public function generateLink(string $urlProducto): string
    {
        $urlCorta = $this->acortarUrlProducto($urlProducto);

        $connector = new MercadoLibreConnector;

        try {
            $response = $connector->send(new GetAffiliateLinkRequest($urlCorta));
            if ($response->successful()) {
                $data = $response->json();
                $link = $data['url'] ?? $data['link'] ?? $data['affiliate_link'] ?? null;
                if ($link !== null && $link !== '') {
                    return $this->appendAffiliateParams($link);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AffiliateService: fallo API ML', [
                'url' => $urlProducto,
                'message' => $e->getMessage(),
            ]);
        }

        return $this->manualFallbackLink($urlCorta);
    }

    /**
     * Enlace canónico de afiliado: URL directa de producto ML + matt_tool, matt_word, affid.
     * No usa la API (evita URLs de tracking click1.mercadolibre que pueden fallar).
     * Usar este para Telegram y para guardar url_afiliado.
     */
    public function getCanonicalAffiliateLink(string $urlProducto): string
    {
        return $this->appendAffiliateParams($this->acortarUrlProducto($urlProducto));
    }

    /**
     * Genera el enlace según la tienda (o la URL si tienda viene vacía). ML siempre lleva parámetros de afiliado;
     * Coppel y otros usan su código desde config (configuracion.affiliate_params).
     * Si la URL es de Mercado Libre se trata como ML aunque tienda no lo indique.
     * Para ML, si se pasa $producto con nombre, se genera enlace a búsqueda ordenada por precio (más barato primero).
     */
    public function getAffiliateLinkForProduct(string $urlProducto, ?string $tienda = null, ?Producto $producto = null): string
    {
        $urlProducto = trim($urlProducto);
        if ($urlProducto === '') {
            return '';
        }
        $slug = $this->normalizeTiendaToSlug($tienda) ?? $this->slugFromUrl($urlProducto);
        if ($slug === 'mercado_libre') {
            $searchUrl = $this->getMercadoLibreSearchByPriceUrl($producto);
            if ($searchUrl !== null) {
                return $this->appendAffiliateParams($searchUrl);
            }
            return $this->getCanonicalAffiliateLink($urlProducto);
        }
        if ($slug !== null) {
            return $this->appendMarketplaceAffiliateParams($urlProducto, $slug);
        }
        return $urlProducto;
    }

    /**
     * Para ML: enlace a listado ordenado por precio (más barato primero) usando el nombre del producto.
     * Así el usuario ve las opciones más económicas y no se pierde la venta. Devuelve null si no hay nombre.
     */
    public function getMercadoLibreSearchByPriceUrl(?Producto $producto): ?string
    {
        if ($producto === null || trim((string) $producto->nombre) === '') {
            return null;
        }
        $term = $this->slugifyForMlSearch(trim($producto->nombre));
        if ($term === '') {
            return null;
        }
        $base = 'https://listado.mercadolibre.com.mx/' . $term;
        return $base . '?_Order=PRICE_ASC';
    }

    /**
     * Convierte el nombre del producto a un término de búsqueda para la URL de listado ML (path).
     */
    private function slugifyForMlSearch(string $nombre): string
    {
        $s = mb_strtolower($nombre);
        $s = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $s);
        $s = preg_replace('/\s+/', '-', trim($s));
        $s = substr($s, 0, 80);
        return $s === '' ? '' : rawurlencode($s);
    }

    /**
     * Alias para compatibilidad. Con tienda usa enlace por marketplace; sin tienda mantiene ML.
     */
    public function convertToAffiliateLink(string $urlProducto, ?string $tienda = null): string
    {
        return $this->getAffiliateLinkForProduct($urlProducto, $tienda);
    }

    /**
     * Devuelve el slug del marketplace: primero por tienda, si no por URL (para prioridad Telegram y enlaces).
     * Así los productos de ML se reconocen aunque tienda venga vacía o incorrecta.
     */
    public function getSlugFromTienda(?string $tienda, ?string $urlProducto = null): ?string
    {
        return $this->normalizeTiendaToSlug($tienda) ?? ($urlProducto !== null && $urlProducto !== '' ? $this->slugFromUrl($urlProducto) : null);
    }

    /**
     * Inferir slug del marketplace desde la URL del producto (ML, Coppel, Walmart).
     * Útil cuando tienda viene vacía o incorrecta para que ML sea más preciso.
     */
    public function slugFromUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === false || $host === null) {
            return null;
        }
        $host = strtolower($host);
        if (str_contains($host, 'mercadolibre') || str_contains($host, 'mercadolibre.com')) {
            return 'mercado_libre';
        }
        if (str_contains($host, 'coppel')) {
            return 'coppel';
        }
        if (str_contains($host, 'walmart')) {
            return 'walmart';
        }
        if (str_contains($host, 'elektra.mx')) {
            return 'elektra';
        }
        return null;
    }

    /**
     * Normaliza el nombre de tienda del producto al slug del marketplace (mercado_libre, coppel, walmart, elektra).
     */
    private function normalizeTiendaToSlug(?string $tienda): ?string
    {
        if ($tienda === null || trim($tienda) === '') {
            return null;
        }
        $t = strtolower(trim($tienda));
        if (str_contains($t, 'mercado') || $t === 'ml' || str_contains($t, 'mercadolibre')) {
            return 'mercado_libre';
        }
        if (str_contains($t, 'coppel')) {
            return 'coppel';
        }
        if (str_contains($t, 'walmart')) {
            return 'walmart';
        }
        if (str_contains($t, 'elektra')) {
            return 'elektra';
        }
        return null;
    }

    /**
     * Añade parámetros de afiliado del marketplace (configuracion.affiliate_params: { "param": "valor" }).
     * Si no hay config, devuelve la URL sin cambios.
     */
    private function appendMarketplaceAffiliateParams(string $url, string $slug): string
    {
        $marketplace = Marketplace::query()->where('slug', $slug)->where('es_activo', true)->first();
        if (! $marketplace || ! is_array($marketplace->configuracion ?? null)) {
            return $url;
        }
        $params = $marketplace->configuracion['affiliate_params'] ?? null;
        if (! is_array($params) || $params === []) {
            return $url;
        }
        $url = trim($url);
        $separator = str_contains($url, '?') ? '&' : '?';
        $query = http_build_query(array_filter($params, fn ($v) => $v !== null && $v !== ''));
        if ($query === '') {
            return $url;
        }
        return $url . $separator . $query;
    }

    /** TTL de caché para la configuración del marketplace (segundos). */
    private const AFFILIATE_CONFIG_CACHE_TTL = 3600; // 60 minutos

    /**
     * Devuelve la configuración de afiliado: prioridad Marketplace (slug mercado_libre) activo, sino config.
     * Cacheada 60 minutos para no saturar MySQL en picos de tráfico.
     *
     * @return array{app_id: string, affid: string, matt_word: string}
     */
    public function getAffiliateConfig(): array
    {
        return Cache::remember(Marketplace::AFFILIATE_CONFIG_CACHE_KEY, self::AFFILIATE_CONFIG_CACHE_TTL, function (): array {
            $marketplace = Marketplace::mercadoLibreActivo();
            if ($marketplace) {
                return [
                    'app_id' => $marketplace->app_id ?? config('services.mercadolibre.app_id'),
                    'affid' => $marketplace->affiliate_id ?? config('services.mercadolibre.affid'),
                    'matt_word' => $marketplace->getMattWord(),
                ];
            }
            return [
                'app_id' => config('services.mercadolibre.app_id'),
                'affid' => config('services.mercadolibre.affid'),
                'matt_word' => config('services.mercadolibre.matt_word', 'mayoreo_cloud'),
            ];
        });
    }

    /**
     * Fuerza siempre la concatenación de los tres parámetros de afiliado al final de la URL.
     * Usa Marketplace (mercado_libre) si existe y está activo; si no, config.
     * Link final: url?matt_tool=...&matt_word=...&affid=...
     */
    public function appendAffiliateParams(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        $url = $this->stripAffiliateParamsFromUrl($url);
        $config = $this->getAffiliateConfig();
        $params = 'matt_tool=' . ($config['app_id'] ?? '') . '&matt_word=' . ($config['matt_word'] ?? 'mayoreo_cloud') . '&affid=' . ($config['affid'] ?? '');
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . $params;
    }

    /**
     * Quita matt_tool, matt_word y affid de la query string para no duplicar al forzar los tres.
     */
    private function stripAffiliateParamsFromUrl(string $url): string
    {
        $question = strpos($url, '?');
        if ($question === false) {
            return $url;
        }
        $base = substr($url, 0, $question);
        $query = substr($url, $question + 1);
        parse_str($query, $params);
        unset($params['matt_tool'], $params['matt_word'], $params['affid']);
        if ($params === []) {
            return $base;
        }
        return $base . '?' . http_build_query($params);
    }

    /**
     * Failsafe: link manual con parámetros de afiliado (matt_tool, matt_word, affid).
     */
    private function manualFallbackLink(string $urlProducto): string
    {
        return $this->appendAffiliateParams($urlProducto);
    }

    /**
     * Acorta la URL de producto ML a la forma mínima: dominio + /p/MLM123 (sin query ni fragment).
     * Así el enlace es corto y sigue funcionando; la afiliación se añade con matt_tool.
     */
    private function acortarUrlProducto(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        // Quitar fragmento (#...)
        $posHash = strpos($url, '#');
        if ($posHash !== false) {
            $url = substr($url, 0, $posHash);
        }
        // Extraer ID de producto: MLM123, MLA123, MLB123
        if (preg_match('/\b(MLM|MLA|MLB)\d+/i', $url, $m)) {
            $id = strtoupper($m[0]);
            $site = strtolower($m[1]);
            return "https://www.mercadolibre.com.mx/p/{$id}";
        }
        // Si no hay ID claro, quitar solo query string para acortar un poco
        $posQ = strpos($url, '?');
        if ($posQ !== false) {
            $url = substr($url, 0, $posQ);
        }
        return $url;
    }
}
