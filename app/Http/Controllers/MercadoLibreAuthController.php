<?php

namespace App\Http\Controllers;

use App\Services\MercadoLibreTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
/**
 * Flujo OAuth de Mercado Libre: login (redirige a ML), callback (intercambia código por tokens).
 * El refresco automático lo hace MercadoLibreTokenService cuando el motor pide el token.
 */
class MercadoLibreAuthController extends Controller
{
    /** URL de autorización de Mercado Libre México. */
    private const AUTH_URL = 'https://auth.mercadolibre.com.mx/authorization';

    /**
     * Redirige al usuario a Mercado Libre para autorizar la aplicación.
     * Ruta: GET /mercado-libre/login
     */
    public function login(): RedirectResponse
    {
        $appId = config('services.mercado_libre.app_id');
        $redirectUri = config('services.mercado_libre.redirect_uri');
        if ($appId === null || $appId === '' || $redirectUri === null || $redirectUri === '') {
            Log::warning('MercadoLibreAuthController: ML_APP_ID o ML_REDIRECT_URI no configurados');

            return redirect()->route('home')->with('error', 'Configuración de Mercado Libre incompleta.');
        }

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'scope' => 'offline_access', // necesario para que ML devuelva refresh_token y no caduque en horas
        ]);

        return redirect(self::AUTH_URL . '?' . $params);
    }

    /**
     * Recibe el código de ML, lo intercambia por Access Token y Refresh Token, y los guarda.
     * Ruta: GET /mercado-libre/callback?code=...
     */
    public function callback(Request $request): RedirectResponse
    {
        $codigo = $request->query('code');
        if ($codigo === null || $codigo === '') {
            Log::warning('MercadoLibreAuthController: callback sin código');

            return redirect()->route('home')->with('error', 'Mercado Libre no devolvió código de autorización.');
        }

        $tokens = MercadoLibreTokenService::intercambiarCodigoPorTokens($codigo);
        if ($tokens === null) {
            return redirect()->route('home')->with('error', 'No se pudieron obtener los tokens de Mercado Libre.');
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
}
