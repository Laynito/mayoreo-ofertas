<?php

namespace App\Console\Commands;

use App\Models\HistorialPrecio;
use App\Models\Producto;
use Illuminate\Console\Command;

/**
 * Borra productos de Mercado Libre creados hoy sin imagen_url, para que el rastreo los vuelva a detectar con foto.
 */
class MercadoLibreLimpiarSinImagen extends Command
{
    protected $signature = 'ml:limpiar-sin-imagen
                            {--force : No pedir confirmación}
                            {--dias=1 : Días hacia atrás (1 = solo hoy)}';

    protected $description = 'Borra productos ML creados recientemente sin imagen para que el bot los re-detecte con foto';

    public function handle(): int
    {
        $dias = (int) $this->option('dias');
        $desde = now()->subDays($dias)->startOfDay();

        $query = Producto::where('tienda_origen', 'Mercado Libre')
            ->where(function ($q): void {
                $q->whereNull('imagen_url')->orWhere('imagen_url', '');
            })
            ->where('created_at', '>=', $desde);

        $total = $query->count();
        if ($total === 0) {
            $this->info('No hay productos de Mercado Libre sin imagen desde ' . $desde->toDateTimeString() . '.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("¿Borrar {$total} productos ML sin imagen (desde {$desde->toDateString()})? Se eliminará también su historial de precios.")) {
            $this->info('Operación cancelada.');

            return self::SUCCESS;
        }

        $ids = (clone $query)->pluck('id');
        HistorialPrecio::whereIn('producto_id', $ids)->delete();
        $borrados = $query->delete();
        $this->info("Eliminados {$borrados} productos de Mercado Libre sin imagen. El próximo rastreo los volverá a detectar con foto si el motor ya extrae imagen_url.");

        return self::SUCCESS;
    }
}
