<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Ejecuta el comando rastreo:tienda en segundo plano (desde el panel Tiendas → Ejecutar rastreo).
 */
class EjecutarRastreoTiendaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public string $tiendaNombre
    ) {}

    public function handle(): void
    {
        Log::info('EjecutarRastreoTiendaJob: iniciando rastreo', ['tienda' => $this->tiendaNombre]);
        Artisan::call('rastreo:tienda', ['tienda' => $this->tiendaNombre]);
        Log::info('EjecutarRastreoTiendaJob: rastreo finalizado', ['tienda' => $this->tiendaNombre]);
    }
}
