<?php

namespace App\Services;

use App\Models\Configuracion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Monetiza enlaces usando la API oficial de Admitad (token + deeplink).
 * Configuración vía config('services.admitad'). Si la API falla, devuelve la URL original para no romper el bot.
 */
class AffiliateLinkService
{
    private const CACHE_KEY_TOKEN = 'admitad_token';
    private const SCOPE_DEEPLINK = 'deeplink_generator';
    private const SUBID_CANAL = 'Mayoreo_Cloud_Bot';

    /** Tiendas para las que se genera enlace Admitad en los botones "Ver en Tienda". */
    private const TIENDAS_CON_AFILIADOS = ['Coppel'];

    public function __construct(
        private readonly AdmitadService $admitadService
    ) {}

    /**
     * Obtiene el access_token de Admitad. POST a https://api.admitad.com/token/ con Basic (base64_header).
     * Guarda el token en Cache::put('admitad_token', $token, $expires_in) para reutilizarlo y no saturar la API.
     */
    public function obtenerAccessToken(): ?string
    {
        $cached = Cache::get(self::CACHE_KEY_TOKEN);
        if ($cached !== null && $cached !== '') {
            return $cached;
        }

        $admitad = config('services.admitad', []);
        $clientId = $admitad['client_id'] ?? '';
        $base64Header = $admitad['base64_header'] ?? '';
        if ($clientId === '' || $base64Header === '') {
            Log::debug('AffiliateLinkService: client_id o base64_header no configurados en services.admitad.');
            return null;
        }

        $baseUrl = rtrim(Configuracion::getAdmitadBaseUrl() ?? 'https://api.admitad.com', '/');
        $tokenUrl = $baseUrl . '/token/';
        $response = Http::asForm()
            ->withHeaders([
                'Authorization' => 'Basic ' . $base64Header,
                'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
            ])
            ->post($tokenUrl, [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'scope' => self::SCOPE_DEEPLINK,
            ]);

        if (! $response->successful()) {
            Log::warning('AffiliateLinkService: fallo al obtener token Admitad', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        if ($token !== null && $expiresIn > 0) {
            Cache::put(self::CACHE_KEY_TOKEN, $token, now()->addSeconds($expiresIn - 60));
        }

        return $token;
    }

    /**
     * Transforma la URL en deeplink vía API. Envía $subid a Admitad (mismo valor que en estadísticas de clics).
     * Fallback: devuelve la URL original.
     */
    public function convertir(string $urlOriginal, string $subid = 'Mayoreo_Cloud_Bot'): string
    {
        if ($urlOriginal === '' || ! str_starts_with($urlOriginal, 'http')) {
            return $urlOriginal;
        }

        $websiteId = Configuracion::getAdmitadPublisherId() ?? config('services.admitad.website_id', '');
        $campaignId = config('services.admitad.campaign_id', '');
        if ($websiteId === '' || $campaignId === '') {
            Log::debug('AffiliateLinkService: website_id o campaign_id no configurados; no se usa API.');
            return $urlOriginal;
        }

        $token = $this->obtenerAccessToken();
        if ($token === null) {
            return $urlOriginal;
        }

        $subidEnviar = $subid !== '' ? $subid : self::SUBID_CANAL;
        $baseUrl = rtrim(Configuracion::getAdmitadBaseUrl() ?? 'https://api.admitad.com', '/');
        $url = sprintf(
            '%s/deeplink/%s/advcampaign/%s/',
            $baseUrl,
            $websiteId,
            $campaignId
        );
        $response = Http::withToken($token)
            ->get($url, [
                'ulp' => $urlOriginal,
                'subid' => $subidEnviar,
            ]);

        if (! $response->successful()) {
            Log::warning('AffiliateLinkService: fallo al generar deeplink', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return $urlOriginal;
        }

        $list = $response->json();
        if (! is_array($list) || count($list) === 0) {
            return $urlOriginal;
        }
        $first = $list[0];
        $link = $first['link'] ?? null;
        if ($link !== null && $link !== '' && str_starts_with($link, 'http')) {
            return $link;
        }

        return $urlOriginal;
    }

    /**
     * URL para el botón "Ver en Tienda". Acepta $canal como segundo parámetro; se envía como subid a la API de Admitad.
     * Si la API falla, devuelve la URL original para no romper el flujo del bot.
     */
    public function enlaceParaTelegram(string $urlOriginal, string $tiendaOrigen = '', string $canal = 'Mayoreo_Cloud_Bot'): string
    {
        if ($urlOriginal === '' || ! str_starts_with($urlOriginal, 'http')) {
            return $urlOriginal;
        }

        if (! $this->tiendaSoportaAfiliados($urlOriginal, $tiendaOrigen)) {
            return $urlOriginal;
        }

        $subid = $canal !== '' ? $canal : self::SUBID_CANAL;
        try {
            $converted = $this->convertir($urlOriginal, $subid);
            if ($converted !== $urlOriginal) {
                return $converted;
            }
        } catch (\Throwable $e) {
            Log::warning('AffiliateLinkService: excepción en enlaceParaTelegram, devolviendo URL original', [
                'message' => $e->getMessage(),
            ]);
            return $urlOriginal;
        }

        return $this->admitadService->generarDeeplink($urlOriginal);
    }

    private function tiendaSoportaAfiliados(string $urlOriginal, string $tiendaOrigen): bool
    {
        $tiendaNormalizada = trim($tiendaOrigen);
        if (in_array($tiendaNormalizada, self::TIENDAS_CON_AFILIADOS, true)) {
            return true;
        }
        return str_contains($urlOriginal, 'coppel.com');
    }
}
