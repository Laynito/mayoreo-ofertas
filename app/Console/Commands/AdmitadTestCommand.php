<?php

namespace App\Console\Commands;

use App\Http\Integrations\Admitad\AdmitadConnector;
use App\Http\Integrations\Admitad\Requests\GetMeRequest;
use App\Http\Integrations\Admitad\Requests\GetTokenRequest;
use Illuminate\Console\Command;

class AdmitadTestCommand extends Command
{
    protected $signature = 'admitad:test';

    protected $description = 'Prueba la conexión con la API de Admitad: obtiene token y datos del usuario (GET /me/)';

    public function handle(): int
    {
        if (! config('services.admitad.client_id') || ! config('services.admitad.client_secret')) {
            $this->error('Configura ADMITAD_CLIENT_ID y ADMITAD_CLIENT_SECRET en .env');
            return self::FAILURE;
        }

        $this->info('Probando conexión con Admitad...');

        $connector = new AdmitadConnector();

        // 1. Token
        $this->info('Obteniendo token...');
        $tokenResponse = $connector->send(new GetTokenRequest());
        if (! $tokenResponse->successful()) {
            $this->error('Error al obtener token: ' . $tokenResponse->status());
            $this->line($tokenResponse->body());
            return self::FAILURE;
        }
        $tokenData = $tokenResponse->json();
        $this->info('Token OK. Expira en ' . ($tokenData['expires_in'] ?? '?') . ' segundos.');

        // 2. Usuario (GET /me/)
        $this->info('Obteniendo datos del usuario (GET /me/)...');
        $meResponse = $connector->send(new GetMeRequest());
        if (! $meResponse->successful()) {
            $this->error('Error en GET /me/: ' . $meResponse->status());
            $this->line($meResponse->body());
            return self::FAILURE;
        }
        $me = $meResponse->json();
        $this->newLine();
        $this->info('Conexión correcta. Datos del publisher:');
        $this->table(
            ['Campo', 'Valor'],
            [
                ['id', $me['id'] ?? '-'],
                ['username', $me['username'] ?? '-'],
                ['first_name', $me['first_name'] ?? '-'],
                ['last_name', $me['last_name'] ?? '-'],
                ['language', $me['language'] ?? '-'],
                ['default_currency', $me['default_currency'] ?? '-'],
                ['country', $me['country'] ?? '-'],
            ]
        );

        $this->newLine();
        $this->comment('Siguientes pasos: listar programas (advcampaigns), cupones, banners o generar enlaces. Ver docs/ADMITAD.md.');

        return self::SUCCESS;
    }
}
