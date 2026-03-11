<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class FacebookPublishCommand extends Command
{
    protected $signature = 'facebook:publish
                            {--dry-run : Solo muestra qué se publicaría, sin publicar}';

    protected $description = 'Publica en la Fan Page de Facebook las ofertas con mayor descuento (usa API configurada en .env)';

    public function handle(): int
    {
        $pythonBinary = base_path('python/venv/bin/python');
        $script = base_path('python/facebook_publisher.py');

        if (! is_file($pythonBinary)) {
            $this->error('No se encuentra python/venv/bin/python. Crea el venv en python/ e instala dependencias.');

            return self::FAILURE;
        }

        if (! is_file($script)) {
            $this->error("No se encuentra el script: {$script}");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Modo dry-run: se ejecutaría el publicador (sin modificar nada en el script).');
            $this->info('Para publicar de verdad, ejecuta: php artisan facebook:publish');
            return self::SUCCESS;
        }

        $this->info('Publicando ofertas en Facebook (credenciales desde .env o panel Marketplace → Facebook)...');

        $result = Process::path(base_path())
            ->timeout(120)
            ->run([$pythonBinary, '-u', $script]);

        if ($result->successful()) {
            $this->info($result->output());
            return self::SUCCESS;
        }

        $this->error('Error al publicar en Facebook:');
        $this->line($result->errorOutput() ?: $result->output());
        return self::FAILURE;
    }
}
