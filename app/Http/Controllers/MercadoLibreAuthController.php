<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Services\MercadoLibreTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Flujo OAuth de Mercado Libre: login (redirige a ML), callback (intercambia código por tokens).
 * Cumple documentación oficial: state (CSRF), PKCE (code_challenge/code_verifier), Accept/Content-Type en /oauth/token.
 * El refresco automático lo hace MercadoLibreTokenService cuando el motor pide el token.
 *
 * Nota: Algunos usan Postman para probar a mano la API (GET /users/me con header Authorization: Bearer TOKEN);
 * no es necesario para que la app funcione, solo sirve para depurar el token.
 *
 * Bypass de certificación: si el login/callback falla, este controlador NUNCA borra ni modifica
 * el ID de afiliado (ml_affiliate_id) en la base de datos, para que el bot siga publicando
 * con enlaces largos (&micosmtics=187001804) en modo scraping.
 */
class MercadoLibreAuthController extends Controller
{
    private const SESSION_STATE = 'mercado_libre_oauth_state';
    private const SESSION_CODE_VERIFIER = 'mercado_libre_oauth_code_verifier';

    /** URL de autorización de Mercado Libre México. */
    private const AUTH_URL = 'https://auth.mercadolibre.com.mx/authorization';

    /**
     * Redirige al usuario a Mercado Libre para autorizar la aplicación.
     * Incluye state (recomendado por ML) y PKCE (obligatorio si la app tiene "configuración de seguridad" con PKCE activado).
     * Ruta: GET /mercado-libre/login
     */
    public function login(): RedirectResponse
    {
        $appId = Configuracion::getMlAppId();
        $redirectUri = config('services.mercado_libre.redirect_uri');
        Log::info('MercadoLibreAuthController: Enviando Redirect URI: ' . ($redirectUri ?? '(null)'));
        if ($appId === null || $appId === '' || $redirectUri === null || $redirectUri === '') {
            Log::warning('MercadoLibreAuthController: App ID (Ajustes/.env) o ML_REDIRECT_URI no configurados');

            return redirect()->route('home')->with('error', 'Configuración de Mercado Libre incompleta.');
        }

        $state = Str::random(40);
        $codeVerifier = $this->generarCodeVerifier();
        $codeChallenge = $this->generarCodeChallenge($codeVerifier);

        session()->put(self::SESSION_STATE, $state);
        session()->put(self::SESSION_CODE_VERIFIER, $codeVerifier);

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'scope' => 'offline_access',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect(self::AUTH_URL . '?' . $params);
    }

    /**
     * Recibe el código de ML, valida state, intercambia código por tokens (con code_verifier si usamos PKCE) y guarda.
     * Ruta: GET /mercado-libre/callback?code=...&state=...
     */
    public function callback(Request $request): RedirectResponse
    {
        $codigo = $request->query('code');
        if ($codigo === null || $codigo === '') {
            Log::warning('MercadoLibreAuthController: callback sin código');
            $this->limpiarSessionOAuth();
            return redirect()->route('home')->with('error', 'Mercado Libre no devolvió código de autorización.');
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
            return redirect()->route('home')->with('error', 'No se pudieron obtener los tokens de Mercado Libre. Comprueba que ML_REDIRECT_URI coincida exactamente con la URL configurada en tu app y que el usuario sea cuenta principal (no colaborador).');
        }

        $refreshToken = $tokens['refresh_token'] ?? '';
        MercadoLibreTokenService::guardarTokens(
            $tokens['access_token'],
            $refreshToken,
            $tokens['expires_in']
        );

        Log::info('MercadoLibreAuthController: tokens de Mercado Libre guardados correctamente.');

        $mensaje = 'Mercado Libre conectado. El rastreo de ofertas usará la API oficial.';
        if ($refreshToken === '') {
            $mensaje .= ' Revisa en el panel de desarrolladores de ML que la app tenga el scope offline_access y vuelve a hacer login para obtener el refresh token.';
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
