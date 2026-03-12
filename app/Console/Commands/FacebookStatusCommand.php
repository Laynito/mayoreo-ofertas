<?php

namespace App\Console\Commands;

use App\Services\FacebookService;
use Illuminate\Console\Command;

class FacebookStatusCommand extends Command
{
    protected $signature = 'facebook:status';

    protected $description = 'Comprueba el estado de la conexión con la API de Facebook (token y página)';

    public function handle(FacebookService $facebook): int
    {
        $status = $facebook->getApiStatus();

        if ($status['ok']) {
            $this->info('Estado: Conectado');
            $this->line('  ' . $status['message']);
            if (! empty($status['page_name'])) {
                $this->line('  Página: ' . $status['page_name']);
            }
            $this->line('  Credenciales: configuradas');
            $this->line('  Token: válido');

            return self::SUCCESS;
        }

        $this->error('Estado: Error');
        $this->line('  ' . $status['message']);
        if (! empty($status['error_code'])) {
            $this->line('  Código: ' . $status['error_code']);
        }
        if (! $status['credentials']) {
            $this->warn('  Configura FB_PAGE_ID y FB_PAGE_ACCESS_TOKEN en .env o en Marketplace → Facebook.');
        }

        return self::FAILURE;
    }
}
