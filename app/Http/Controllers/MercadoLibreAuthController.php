<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Services\MercadoLibreTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Flujo OAuth de Mercado Libre según documentación oficial:
 * https://developers.mercadolibre.com.mx/es_ar/autenticacion-y-autorizacion
 *
 * Checklist implementado:
 * 1) Autorización: GET auth.mercadolibre.{país}/authorization con response_type=code, client_id, redirect_uri
 *    (exacto al registrado), state (CSRF), code_challenge + code_challenge_method=S256 (PKCE). Dominio por país vía ML_AUTH_DOMAIN.
 * 2) Intercambio code por token: POST api.mercadolibre.com/oauth/token con Accept/Content-Type, grant_type=authorization_code,
 *    client_id, client_secret, code, redirect_uri, code_verifier (si PKCE).
 * 3) Refresh token: POST oauth/token con grant_type=refresh_token; se guarda el nuevo refresh_token (uso único).
 *
 * El refresco automático lo hace MercadoLibreTokenService cuando el motor pide el token.
 * Bypass: si login/callback falla, no se modifica ml_affiliate_id (bot sigue con enlaces largos en modo scraping).
 */
class MercadoLibreAuthController extends Controller
{
    private const SESSION_STATE = 'mercado_libre_oauth_state';
    private const SESSION_CODE_VERIFIER = 'mercado_libre_oauth_code_verifier';

    /** Base URL de autorización (dominio por país: doc ML "cambiar .com.ar por el dominio del país"). */
    private static function urlAutorizacion(): string
    {
        $dominio = config('services.mercado_libre.auth_domain', 'mercadolibre.com.mx');
        return 'https://auth.' . $dominio . '/authorization';
    }

    /**
     * Redirige al usuario a Mercado Libre para autorizar la aplicación.
     * Incluye state (recomendado por ML) y PKCE (obligatorio si la app tiene "configuración de seguridad" con PKCE activado).
     * Ruta: GET /mercado-libre/login
     */
    public function login(): RedirectResponse
    {
        $appId = Configuracion::getMlAppId();
        $redirectUri = Configuracion::getMlRedirectUri();
        Log::info('MercadoLibreAuthController: Enviando Redirect URI: ' . ($redirectUri ?? '(null)'));
        if ($appId === null || $appId === '' || $redirectUri === null || $redirectUri === '') {
            Log::warning('MercadoLibreAuthController: App ID (ML_APP_ID) o Redirect URI (ML_REDIRECT_URI) no configurados');

            return redirect()->route('home')->with('error', 'Configuración de Mercado Libre incompleta (ML_APP_ID y ML_REDIRECT_URI en .env o Ajustes).');
        }

        $state = Str::random(40);
        $codeVerifier = $this->generarCodeVerifier();
        $codeChallenge = $this->generarCodeChallenge($codeVerifier);

        session()->put(self::SESSION_STATE, $state);
        session()->put(self::SESSION_CODE_VERIFIER, $codeVerifier);

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $appId,       // ML_APP_ID (services.mercado_libre.app_id)
            'redirect_uri' => $redirectUri, // ML_REDIRECT_URI (services.mercado_libre.redirect_uri)
            'scope' => 'offline_access',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect(self::urlAutorizacion() . '?' . $params);
    }

    /**
     * Recibe el código de ML, valida state, intercambia código por tokens (con code_verifier si usamos PKCE) y guarda.
     * Ruta: GET /mercado-libre/callback?code=...&state=...
     */
    public function callback(Request $request): RedirectResponse
    {
        $codigo = $request->query('code');
        if ($codigo === null || $codigo === '') {
            $errorMl = $request->query('error');
            $errorDesc = $request->query('error_description');
            $queryParams = $request->query();
            $queryArray = is_array($queryParams) ? $queryParams : $queryParams->all();
            Log::warning('MercadoLibreAuthController: callback sin código', [
                'query' => $queryArray,
                'error' => $errorMl,
                'error_description' => $errorDesc,
            ]);
            $this->limpiarSessionOAuth();

            $mensaje = 'Mercado Libre no devolvió código de autorización.';
            if ($errorMl !== null || $errorDesc !== null) {
                $mensaje .= ' ' . ($errorDesc ?: $errorMl);
            }
            $redirectUriActual = Configuracion::getMlRedirectUri();
            $mensaje .= ' Comprueba: 1) ML_REDIRECT_URI en .env debe ser exactamente la misma URL que configuraste en la app de ML (ej: https://tudominio.com/mercado-libre/callback). 2) No canceles en la pantalla de ML. 3) Vuelve a intentar desde /mercado-libre/login.';
            if ($redirectUriActual !== null && $redirectUriActual !== '') {
                $mensaje .= ' URI configurada actualmente: ' . $redirectUriActual;
            } else {
                $mensaje .= ' ML_REDIRECT_URI está vacío en .env; usa por ejemplo: ' . rtrim(config('app.url', 'https://tudominio.com'), '/') . '/mercado-libre/callback';
            }

            return redirect()->route('home')->with('error', $mensaje);
        }

        $stateRecibido = $request->query('state');
        $stateGuardado = session()->pull(self::SESSION_STATE);
        if ($stateGuardado === null || $stateRecibido !== $stateGuardado) {
            Log::warning('MercadoLibreAuthController: state inválido o no coincide (CSRF)');
            $this->limpiarSessionOAuth();
            return redirect()->route('home')->with('error', 'Sesión de autorización inválida. Vuelve a intentar desde /mercado-libre/login.');
        }

        $codeVerifier = session()->pull(self::SESSION_CODE_VERIFIER);

        $tokens = MercadoLibreTokenService::intercambiarCodigoPorTokens($codigo, $codeVerifier);
        if ($tokens === null) {
            return redirect()->route('home')->with('error', 'No se pudieron obtener los tokens. Revisa ML_APP_ID, ML_SECRET_KEY y ML_REDIRECT_URI en .env o Ajustes.');
        }
        if (isset($tokens['error'])) {
            $mensaje = $tokens['error_description'] ?? $tokens['error'];
            if (($tokens['error'] ?? '') === 'invalid_grant') {
                $mensaje .= ' Comprueba que ML_REDIRECT_URI coincida exactamente con la URL de tu app y que el usuario sea cuenta principal (no colaborador).';
            }
            return redirect()->route('home')->with('error', 'Mercado Libre: ' . $mensaje);
        }

        $refreshToken = $tokens['refresh_token'] ?? '';
        MercadoLibreTokenService::guardarTokens(
            $tokens['access_token'],
            $refreshToken,
            $tokens['expires_in']
        );

        Log::info('MercadoLibreAuthController: tokens de Mercado Libre guardados correctamente.');

        $mensaje = 'Mercado Libre conectado.';
        if ($refreshToken === '') {
            $mensaje .= ' Revisa en el panel de ML que la app tenga scope offline_access y vuelve a hacer login para el refresh token.';
        }

        return redirect()->route('home')->with('success', $mensaje);
    }

    /** Genera code_verifier (43-128 caracteres, RFC 7636). */
    private function generarCodeVerifier(): string
    {
        $bytes = random_bytes(32);

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Genera code_challenge = base64url(SHA256(code_verifier)). */
    private function generarCodeChallenge(string $codeVerifier): string
    {
        $hash = hash('sha256', $codeVerifier, true);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    private function limpiarSessionOAuth(): void
    {
        session()->forget([self::SESSION_STATE, self::SESSION_CODE_VERIFIER]);
    }
}
