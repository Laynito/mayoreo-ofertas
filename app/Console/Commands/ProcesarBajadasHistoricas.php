<?php

namespace App\Console\Commands;

use App\Jobs\ProcesarBajadaDePrecioJob;
use Illuminate\Console\Command;

/**
 * Encola o ejecuta la detección de bajadas históricas (≥20% vs registro anterior).
 * Útil para ejecutar a mano o desde cron además del encolado automático tras cada rastreo.
 */
class ProcesarBajadasHistoricas extends Command
{
    protected $signature = 'ofertas:procesar-bajadas
                            {--sync : Ejecutar en sincróno en lugar de encolar}
                            {--productos= : IDs de productos separados por coma (opcional)}';

    protected $description = 'Evalúa bajadas de precio ≥20% vs historial y notifica con captura Browsershot';

    public function handle(): int
    {
        $ids = $this->option('productos');
        $productoIds = null;
        if (is_string($ids) && $ids !== '') {
            $productoIds = array_map('intval', array_filter(explode(',', $ids)));
        }

        if ($this->option('sync')) {
            $job = new ProcesarBajadaDePrecioJob($productoIds);
            $job->handle(app(\App\Services\NotificadorTelegram::class));
            $this->info('Procesamiento de bajadas históricas ejecutado en sincróno.');
        } else {
            ProcesarBajadaDePrecioJob::dispatch($productoIds);
            $this->info('Job de bajadas históricas encolado.');
        }

        return 0;
    }
}
