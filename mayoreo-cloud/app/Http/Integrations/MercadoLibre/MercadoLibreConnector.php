<?php

namespace App\Http\Integrations\MercadoLibre;

use Illuminate\Support\Facades\Cache;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Auth\TokenAuthenticator;
use App\Http\Integrations\MercadoLibre\Requests\GetTokenRequest;
use Saloon\Exceptions\Request\Statuses\UnauthorizedException;

class MercadoLibreConnector extends Connector
{
    public const TOKEN_CACHE_KEY = 'mercadolibre_access_token';

    public function resolveBaseUrl(): string
    {
        return 'https://api.mercadolibre.com';
    }

    /**
     * Antes de cada request: inyectar access_token. Si no hay token, obtenerlo (refresh o client_credentials).
     * No autenticar en la petición de token.
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
     * Token desde cache o renovado vía API.
     */
    private function getOrRefreshToken(): ?string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if ($cached !== null) {
            return $cached;
        }

        $refreshToken = config('services.mercadolibre.refresh_token');
        if ($refreshToken) {
            $response = $this->send(new GetTokenRequest('refresh_token', $refreshToken));
            if ($response->successful()) {
                return $this->storeTokenFromResponse($response);
            }
        }

        $response = $this->send(new GetTokenRequest('client_credentials'));
        if ($response->successful()) {
            return $this->storeTokenFromResponse($response);
        }

        return null;
    }

    private function storeTokenFromResponse(\Saloon\Http\Response $response): ?string
    {
        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 21600);
        if ($token !== null) {
            Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds($expiresIn - 60));
        }
        return $token;
    }

    /**
     * Limpiar token en cache (p. ej. tras un 401 para forzar refresh en el reintento).
     */
    public static function clearTokenCache(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }
}
