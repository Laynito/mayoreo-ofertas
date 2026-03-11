<?php

namespace App\Http\Integrations\Admitad;

use Illuminate\Support\Facades\Cache;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Auth\BasicAuthenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use App\Http\Integrations\Admitad\Requests\GetTokenRequest;

/**
 * Conector a la API de Admitad (https://api.admitad.com).
 * Autenticación: OAuth 2.0 client_credentials con HTTP Basic (client_id:client_secret).
 * Documentación: https://developers.admitad.com/
 */
class AdmitadConnector extends Connector
{
    public const TOKEN_CACHE_KEY = 'admitad_access_token';

    public function resolveBaseUrl(): string
    {
        return 'https://api.admitad.com';
    }

    /**
     * Solo la petición de token usa Basic Auth; el resto usa Bearer con access_token.
     */
    public function boot(PendingRequest $pendingRequest): void
    {
        if ($pendingRequest->getRequest() instanceof GetTokenRequest) {
            return;
        }

        $token = $this->getOrRefreshToken();
        if ($token !== null) {
            $pendingRequest->authenticate(new TokenAuthenticator($token));
        }
    }

    /**
     * Credenciales Basic para /token/ (client_id = username, client_secret = password).
     */
    protected function defaultAuth(): ?BasicAuthenticator
    {
        $clientId = config('services.admitad.client_id');
        $clientSecret = config('services.admitad.client_secret');

        if ($clientId === null || $clientSecret === null) {
            return null;
        }

        return new BasicAuthenticator($clientId, $clientSecret);
    }

    private function getOrRefreshToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->send(new GetTokenRequest());
        if ($response->successful()) {
            return $this->storeTokenFromResponse($response);
        }

        return null;
    }

    private function storeTokenFromResponse(\Saloon\Http\Response $response): ?string
    {
        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 604800);

        if ($token !== null) {
            Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds($expiresIn - 60));
        }

        return $token;
    }

    public static function clearTokenCache(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }
}
