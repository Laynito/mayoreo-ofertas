<?php

namespace App\Console\Commands;

use App\Models\Configuracion;
use App\Services\MercadoLibreTokenService;
use App\Support\HttpRastreador;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnostica conexión a API ML (petición pública SIN TOKEN) y valida afiliado.
 * No envía Access Token; usa cabeceras de navegador real (Chrome) para evitar 403 tengine con proxy.
 */
class MercadoLibreDiagnostico extends Command
{
    protected $signature = 'mercado-libre:diagnostico';

    protected $description = 'Diagnostica proxy, API de ofertas (sin token) y enlaces con micosmtics';

    private const URL_PRUEBA = 'https://api.mercadolibre.com/promotions/search?site_id=MLM&type=ALL&limit=5';

    /** ID de afiliado para validar que los links se generen bien. */
    private const ID_AFILIADO = '187001804';

    /** Cabeceras de navegador real (Chrome); petición 100% pública, sin token. */
    private static function headersNavegador(): array
    {
        return [
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Accept-Language' => 'es-MX,es;q=0.9',
            'Referer' => 'https://www.mercadolibre.com.mx/',
        ];
    }

    public function handle(): int
    {
        $appId = Configuracion::getMlAppId();
        $secret = Configuracion::getMlSecretKey();
        $redirectUri = config('services.mercado_libre.redirect_uri');

        $this->info('--- Configuración Mercado Libre (OAuth) ---');
        $this->line('ML_APP_ID: ' . ($appId ? '✓ definido' : '✗ vacío'));
        $this->line('ML_SECRET_KEY: ' . ($secret ? '✓ definido' : '✗ vacío'));
        $this->line('ML_REDIRECT_URI: ' . ($redirectUri ?: '✗ vacío'));

        if (empty($appId) || empty($secret) || empty($redirectUri)) {
            $this->error('Completa ML (App ID, Secret) en Ajustes o .env y ML_REDIRECT_URI en .env. Luego: php artisan config:clear');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('--- Tokens en base de datos (configuracion) ---');
        $access = Configuracion::obtener(Configuracion::CLAVE_ML_ACCESS_TOKEN);
        $refresh = Configuracion::obtener(Configuracion::CLAVE_ML_REFRESH_TOKEN);
        $expiresAt = Configuracion::obtener(Configuracion::CLAVE_ML_EXPIRES_AT);

        $this->line('Access token: ' . ($access ? '✓ guardado (' . strlen((string) $access) . ' caracteres)' : '✗ no guardado'));
        $this->line('Refresh token: ' . ($refresh ? '✓ guardado' : '✗ no guardado'));
        if ($expiresAt !== null && $expiresAt !== '') {
            $ts = (int) $expiresAt;
            $this->line('Expira en (Unix): ' . $ts . ' → ' . ($ts > time() ? 'aún válido' : 'expirado'));
        } else {
            $this->line('Expira en: no definido');
        }

        if (empty($access)) {
            $this->warn('Access token no guardado (opcional para este diagnóstico).');
        } else {
            $tokenValido = MercadoLibreTokenService::obtenerAccessTokenValido();
            $this->line('Token válido: ' . ($tokenValido ? '✓' : '✗ (no se usa en la prueba de API)'));
        }

        $this->newLine();
        $this->info('--- Prueba de API (SIN TOKEN, petición pública) ---');
        $proxy = Configuracion::getProxyUrl();
        $headers = self::headersNavegador();
        $this->line($proxy ? 'Usando PROXY_URL. Cabeceras: Chrome User-Agent + Referer.' : 'Sin proxy. Cabeceras: Chrome User-Agent + Referer.');
        $this->line('No se envía Access Token ni Authorization.');

        $request = Http::withHeaders($headers)->timeout(60)->connectTimeout(30);
        $request = HttpRastreador::conProxySiTexto($request, self::URL_PRUEBA);
        $respuesta = $request->get(self::URL_PRUEBA);

        $status = $respuesta->status();
        $this->line('API GET ' . self::URL_PRUEBA);
        $this->line('HTTP status: ' . $status);

        if ($respuesta->successful()) {
            $data = $respuesta->json();
            $resultados = $data['results'] ?? [];
            $total = is_array($resultados) ? count($resultados) : ($data['paging']['total'] ?? 0);
            $this->info('Respuesta: 200 OK. Resultados: ' . $total);

            $affiliateId = Configuracion::getMlAffiliateId() ?: self::ID_AFILIADO;
            $this->newLine();
            $this->info('--- Afiliado (micosmtics) ---');
            $this->line('ID configurado: ' . ($affiliateId ?: 'vacío (usando ' . self::ID_AFILIADO . ' para validación)'));

            if (is_array($resultados) && count($resultados) > 0) {
                $primero = $resultados[0];
                $permalink = $primero['permalink'] ?? $primero['url'] ?? '';
                if ($permalink !== '') {
                    $linkAfiliado = $permalink . (str_contains($permalink, '?') ? '&' : '?') . 'micosmtics=' . $affiliateId;
                    $this->line('Primer ítem: ' . ($primero['title'] ?? $primero['id'] ?? 'N/A'));
                    $this->line('Link con afiliado: ' . $linkAfiliado);
                }
            }
            $this->newLine();
            $this->info('Prueba rastreo real: php artisan rastreo:tienda "Mercado Libre"');
            return self::SUCCESS;
        }

        $body = $respuesta->body();
        $this->error('La API devolvió ' . $status . '. Cuerpo: ' . mb_substr($body, 0, 300));
        if ($status === 403) {
            $this->newLine();
            $this->warn('403 (tengine/PolicyAgent). Sin reintento con token. Rotar sesión del proxy en PROXY_URL (ej. otro session-XXX en Smartproxy).');
        }
        return self::FAILURE;
    }
}
