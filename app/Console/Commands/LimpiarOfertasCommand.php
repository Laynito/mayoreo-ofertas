<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LimpiarOfertasCommand extends Command
{
    protected $signature = 'app:limpiar-ofertas';

    protected $description = 'Vacía por completo la tabla de productos y el historial de precios (truncate; reinicia IDs)';

    public function handle(): int
    {
        Schema::disableForeignKeyConstraints();
        DB::table('producto_precio_historial')->truncate();
        DB::table('productos')->truncate();
        if (Schema::hasTable('jobs')) {
            DB::table('jobs')->truncate();
        }
        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')->truncate();
        }
        Schema::enableForeignKeyConstraints();

        $this->info('Reinicio completo: productos, historial de precios y cola de jobs vaciados. IDs reiniciados.');

        return self::SUCCESS;
    }
}
