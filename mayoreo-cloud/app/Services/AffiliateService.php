<?php

namespace App\Services;

use App\Http\Integrations\MercadoLibre\MercadoLibreConnector;
use App\Http\Integrations\MercadoLibre\Requests\GetAffiliateLinkRequest;
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
     * Alias para compatibilidad con código que llame convertToAffiliateLink.
     * Genera enlace canónico (estable) en lugar de depender de la API de clicks.
     */
    public function convertToAffiliateLink(string $urlProducto): string
    {
        return $this->getCanonicalAffiliateLink($urlProducto);
    }

    /**
     * Fuerza siempre la concatenación de los tres parámetros de afiliado al final de la URL.
     * Link final: url?matt_tool=ML_APP_ID&matt_word=mayoreo_cloud&affid=ML_AFFID
     * Si la URL ya tiene ? se usa &.
     */
    public function appendAffiliateParams(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        $url = $this->stripAffiliateParamsFromUrl($url);
        $toolId = config('services.mercadolibre.app_id');   // matt_tool (App ID)
        $mattWord = config('services.mercadolibre.matt_word', 'mayoreo_cloud');
        $affid = config('services.mercadolibre.affid');    // affid (ID afiliado)
        $params = 'matt_tool=' . $toolId . '&matt_word=' . $mattWord . '&affid=' . $affid;
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
