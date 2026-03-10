<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class MercadoLibreExchangeCodeCommand extends Command
{
    protected $signature = 'ml:exchange-code
                            {code : El code que obtuviste de la URL de redirección después de autorizar}
                            {--redirect_uri= : Redirect URI (debe coincidir con el usado al generar el code)}';

    protected $description = 'Intercambia el code de OAuth de Mercado Libre por access_token y refresh_token. Muestra el refresh_token para guardarlo en .env';

    public function handle(): int
    {
        $code = $this->argument('code');
        $redirectUri = $this->option('redirect_uri') ?: config('services.mercadolibre.redirect_uri');

        if (! $redirectUri) {
            $this->error('Indica el redirect_uri con --redirect_uri= o configura ML_REDIRECT_URI en .env');
            return self::FAILURE;
        }

        $clientId = config('services.mercadolibre.app_id');
        $clientSecret = config('services.mercadolibre.client_secret');

        if (! $clientId || ! $clientSecret) {
            $this->error('Configura ML_APP_ID y ML_CLIENT_SECRET en .env');
            return self::FAILURE;
        }

        $this->info('Intercambiando code por token...');

        $response = Http::asForm()
            ->acceptJson()
            ->post('https://api.mercadolibre.com/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

        if (! $response->successful()) {
            $this->error('Error de la API: ' . $response->status());
            $this->line($response->body());
            return self::FAILURE;
        }

        $data = $response->json();
        $refreshToken = $data['refresh_token'] ?? null;
        $accessToken = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;
        $userId = $data['user_id'] ?? null;

        if (! $refreshToken) {
            $this->warn('La respuesta no incluyó refresh_token. Cuerpo:');
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('--- Refresh token (guárdalo en .env como ML_REFRESH_TOKEN) ---');
        $this->line($refreshToken);
        $this->newLine();
        $this->comment('Access token (válido ' . ($expiresIn ? "{$expiresIn}s" : '?') . '): ' . ($accessToken ? substr($accessToken, 0, 20) . '...' : '—'));
        if ($userId) {
            $this->comment('User ID: ' . $userId);
        }
        $this->newLine();
        $this->info('Añade a tu .env:');
        $this->line('ML_REFRESH_TOKEN="' . $refreshToken . '"');
        $this->newLine();

        return self::SUCCESS;
    }
}
