<?php

namespace App\Console\Commands;

use App\Fabrica\RastreadorFabrica;
use App\Models\HistorialPrecio;
use App\Models\Producto;
use Illuminate\Console\Command;

class LimpiarTiendaRastreo extends Command
{
    protected $signature = 'rastreo:limpiar-tienda
                            {tienda : Nombre de la tienda (ej. Coppel, Walmart) o "all"/"todo" para todas}
                            {--force : No pedir confirmación}';

    protected $description = 'Borra productos e historial de precios de una tienda (o de todas con "all") para volver a subir ofertas';

    public function handle(): int
    {
        $tiendaArgumento = $this->argument('tienda');
        $limpiarTodo = in_array(strtolower($tiendaArgumento), ['all', 'todo', '*'], true);

        if ($limpiarTodo) {
            return $this->limpiarTodo();
        }

        try {
            $tiendaOrigen = RastreadorFabrica::nombreParaBD($tiendaArgumento);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $total = Producto::where('tienda_origen', $tiendaOrigen)->count();
        if ($total === 0) {
            $this->info("No hay productos de [{$tiendaOrigen}]. Nada que limpiar.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("¿Borrar {$total} productos de [{$tiendaOrigen}] y su historial de precios?")) {
            $this->info('Operación cancelada.');

            return self::SUCCESS;
        }

        $ids = Producto::where('tienda_origen', $tiendaOrigen)->pluck('id');
        $historialBorrados = HistorialPrecio::whereIn('producto_id', $ids)->delete();
        $productosBorrados = Producto::where('tienda_origen', $tiendaOrigen)->delete();

        $this->info("Listo: {$productosBorrados} productos y {$historialBorrados} registros de historial eliminados.");
        $this->info("Ejecuta 'php artisan rastreo:tienda {$tiendaArgumento}' para volver a subir las ofertas.");

        return self::SUCCESS;
    }

    /**
     * Borra todos los productos y todo el historial de precios de todas las tiendas.
     */
    protected function limpiarTodo(): int
    {
        $totalProductos = Producto::count();
        $totalHistorial = HistorialPrecio::count();

        if ($totalProductos === 0 && $totalHistorial === 0) {
            $this->info('No hay productos ni historial. Nada que limpiar.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("¿Borrar TODOS los productos ({$totalProductos}) y todo el historial de precios ({$totalHistorial}) de todas las tiendas?")) {
            $this->info('Operación cancelada.');

            return self::SUCCESS;
        }

        $historialBorrados = HistorialPrecio::query()->delete();
        $productosBorrados = Producto::query()->delete();

        $this->info("Listo: {$productosBorrados} productos y {$historialBorrados} registros de historial eliminados (todas las tiendas).");
        $this->info('Ejecuta "php artisan rastreo:tienda <Tienda>" para cada tienda que quieras volver a rastrear.');

        return self::SUCCESS;
    }
}
