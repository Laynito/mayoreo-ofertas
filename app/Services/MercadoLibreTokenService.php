<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Support\HttpRastreador;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Obtiene y renueva el Access Token de Mercado Libre (OAuth).
 * Los tokens se guardan en configuracion; el motor los usa en Authorization: Bearer.
 * Usa cabeceras de navegador real para no dejar rastro de Guzzle/PHP frente a PolicyAgent.
 */
class MercadoLibreTokenService
{
    private const URL_TOKEN = 'https://api.mercadolibre.com/oauth/token';

    /** Margen en segundos: renovar si expira en menos de este tiempo. */
    private const MARGEN_EXPIRACION = 60;

    /**
     * Cabeceras para api.mercadolibre.com: fuente única HttpRastreador::headersNavegador() + Accept/Referer/Origin.
     *
     * @return array<string, string>
     */
    private static function headersNavegador(): array
    {
        return array_merge(HttpRastreador::headersNavegador(), [
            'Accept' => 'application/json',
            'Referer' => 'https://www.mercadolibre.com.mx/',
            'Origin' => 'https://www.mercadolibre.com.mx',
        ]);
    }

    /**
     * Devuelve un Access Token válido (o null si no hay credenciales/tokens).
     * Si el token está expirado o próximo a expirar, intenta renovarlo con el Refresh Token.
     */
    public static function obtenerAccessTokenValido(): ?string
    {
        $appId = config('services.mercado_libre.app_id');
        $secret = config('services.mercado_libre.secret_key');
        if ($appId === null || $appId === '' || $secret === null || $secret === '') {
            return null;
        }

        $accessToken = Configuracion::obtener(Configuracion::CLAVE_ML_ACCESS_TOKEN);
        $expiresAt = Configuracion::obtener(Configuracion::CLAVE_ML_EXPIRES_AT);
        $ahora = time();
        if ($accessToken !== null && $accessToken !== '' && $expiresAt !== null) {
            $expiraEn = (int) $expiresAt;
            if ($ahora < $expiraEn - self::MARGEN_EXPIRACION) {
                return $accessToken;
            }
        }

        $refreshToken = Configuracion::obtener(Configuracion::CLAVE_ML_REFRESH_TOKEN);
        if ($refreshToken === null || $refreshToken === '') {
            return $accessToken !== null && $accessToken !== '' ? $accessToken : null;
        }

        $nuevos = self::refrescarToken($refreshToken);
        if ($nuevos !== null) {
            self::guardarTokens(
                $nuevos['access_token'],
                $nuevos['refresh_token'] ?? $refreshToken,
                (int) ($nuevos['expires_in'] ?? 21600)
            );

            return $nuevos['access_token'];
        }

        return $accessToken !== null && $accessToken !== '' ? $accessToken : null;
    }

    /**
     * Intercambia el código de autorización (recibido en /mercado-libre/callback) por access_token y refresh_token.
     * Flujo estándar Authorization Code sin PKCE: grant_type, client_id, client_secret, code, redirect_uri.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    public static function intercambiarCodigoPorTokens(string $codigo): ?array
    {
        $appId = config('services.mercado_libre.app_id');
        $secret = config('services.mercado_libre.secret_key');
        $redirectUri = config('services.mercado_libre.redirect_uri');
        if ($appId === null || $secret === null || $redirectUri === null) {
            Log::warning('MercadoLibreTokenService: faltan ML_APP_ID, ML_SECRET_KEY o ML_REDIRECT_URI');

            return null;
        }

        $respuesta = Http::withHeaders(self::headersNavegador())
            ->asForm()
            ->post(self::URL_TOKEN, [
                'grant_type' => 'authorization_code',
                'client_id' => $appId,
                'client_secret' => $secret,
                'code' => $codigo,
                'redirect_uri' => $redirectUri,
            ]);

        if (! $respuesta->successful()) {
            Log::warning('MercadoLibreTokenService: error al intercambiar código', [
                'status' => $respuesta->status(),
                'body' => $respuesta->body(),
            ]);

            return null;
        }

        $data = $respuesta->json();
        if (! is_array($data) || empty($data['access_token'])) {
            return null;
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'expires_in' => (int) ($data['expires_in'] ?? 21600),
        ];
    }

    /**
     * Renueva el access token usando el refresh token.
     *
     * @return array{access_token: string, refresh_token?: string, expires_in: int}|null
     */
    public static function refrescarToken(string $refreshToken): ?array
    {
        $appId = config('services.mercado_libre.app_id');
        $secret = config('services.mercado_libre.secret_key');
        if ($appId === null || $appId === '' || $secret === null || $secret === '') {
            return null;
        }

        $respuesta = Http::withHeaders(self::headersNavegador())
            ->asForm()
            ->post(self::URL_TOKEN, [
                'grant_type' => 'refresh_token',
                'client_id' => $appId,
                'client_secret' => $secret,
                'refresh_token' => $refreshToken,
            ]);

        if (! $respuesta->successful()) {
            Log::warning('MercadoLibreTokenService: error al refrescar token', [
                'status' => $respuesta->status(),
                'body' => $respuesta->body(),
            ]);

            return null;
        }

        $data = $respuesta->json();
        if (! is_array($data) || empty($data['access_token'])) {
            return null;
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : null,
            'expires_in' => (int) ($data['expires_in'] ?? 21600),
        ];
    }

    /**
     * Guarda access_token, refresh_token (solo si no está vacío, para no pisar el existente) y expires_at (Unix).
     */
    public static function guardarTokens(string $accessToken, string $refreshToken, int $expiresIn): void
    {
        $expiresAt = time() + $expiresIn;
        Configuracion::guardar(Configuracion::CLAVE_ML_ACCESS_TOKEN, $accessToken);
        if ($refreshToken !== '') {
            Configuracion::guardar(Configuracion::CLAVE_ML_REFRESH_TOKEN, $refreshToken);
        }
        Configuracion::guardar(Configuracion::CLAVE_ML_EXPIRES_AT, (string) $expiresAt);
    }
}
