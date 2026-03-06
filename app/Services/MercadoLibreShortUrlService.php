<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Support\HttpRastreador;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Genera enlaces cortos meli.la usando la API oficial de Marketing/Afiliados de Mercado Libre.
 * POST https://api.mercadolibre.com/affiliates/short_urls
 * Si la API falla o el token está expirado, devuelve null para usar fallback (URL larga con &micosmtics=).
 */
final class MercadoLibreShortUrlService
{
    private const URL_SHORT = 'https://api.mercadolibre.com/affiliates/short_urls';

    /**
     * Genera una URL corta meli.la a partir de la URL larga (debe incluir micosmtics para comisión).
     * Requiere Access Token válido (OAuth). Usa proxy si está configurado (evita cURL 35).
     *
     * @return string|null URL corta (meli.la) o null si falla API/token
     */
    public function acortar(string $urlLarga): ?string
    {
        if ($urlLarga === '' || ! str_starts_with($urlLarga, 'http')) {
            return null;
        }

        $token = MercadoLibreTokenService::obtenerAccessTokenValido();
        if ($token === null || $token === '') {
            Log::debug('MercadoLibreShortUrlService: sin Access Token válido; usar fallback URL larga.');
            return null;
        }

        $apiUrl = Configuracion::getProxyUrl() !== null
            ? HttpRastreador::urlApiMlParaProxy(self::URL_SHORT)
            : self::URL_SHORT;

        try {
            $request = Http::withHeaders(HttpRastreador::headersNavegador())
                ->withToken($token)
                ->timeout(15)
                ->connectTimeout(10)
                ->withOptions(HttpRastreador::opcionesSslBase());
            $request = HttpRastreador::conProxy($request);

            $response = $request->asJson()->post($apiUrl, [
                'url' => $urlLarga,
            ]);

            if (! $response->successful()) {
                Log::warning('MercadoLibreShortUrlService: API short_urls falló', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            // Respuesta típica: { "short_url": "https://meli.la/xxx" } o { "url": "..." }
            $short = $data['short_url'] ?? $data['url'] ?? $data['link'] ?? null;
            if (is_string($short) && $short !== '' && (str_contains($short, 'meli.la') || str_starts_with($short, 'http'))) {
                return $short;
            }

            Log::debug('MercadoLibreShortUrlService: respuesta sin short_url reconocida', ['data' => $data]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('MercadoLibreShortUrlService: excepción al acortar', [
                'mensaje' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extrae el Item ID de Mercado Libre (ej. MLM123456) solo por regex sobre la URL (y fallback sku_tienda).
     * No requiere API ni conexión: funciona 100% local para mostrar "Pega este ID en el buscador: MLM..." en modo scraping.
     * Formato típico en URL: /MLM-123456789-titulo o /MLM123456789; sku_tienda para ML: "ML-MLM123456".
     *
     * @return string|null Código del producto (ej. MLM123456) o null si no se puede extraer
     */
    public static function extraerItemId(?string $url, ?string $skuTienda): ?string
    {
        if ($url !== null && $url !== '') {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                // Formato: /MLM-123456789-titulo o /MLM123456789 o /p/MLM123456789
                if (preg_match('#/(?:p/)?(ML[A-Z]-\d+[a-zA-Z0-9_-]*|ML[A-Z]\d+)#', $path, $m)) {
                    $candidato = trim($m[1], '-');
                    if ($candidato !== '') {
                        return $candidato;
                    }
                }
                // Solo números tras prefijo de sitio (MLM, MLA, etc.)
                if (preg_match('#/(ML[A-Z])-?(\d+)#', $path, $m)) {
                    return $m[1] . $m[2];
                }
            }
        }

        if ($skuTienda !== null && $skuTienda !== '') {
            $sku = trim($skuTienda);
            // sku_tienda para ML suele ser "ML-MLM123456" -> Item ID = MLM123456
            if (str_starts_with($sku, 'ML-')) {
                $id = substr($sku, 3);
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return null;
    }
}
