<?php

namespace App\Console\Commands;

use App\Models\Producto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetProductosKeepChamarraCommand extends Command
{
    protected $signature = 'productos:reset-keep-chamarra
                            {--force : Ejecutar sin confirmación}';

    protected $description = 'Borra todos los productos excepto la chamarra <100 pesos y la reasigna como ID 1 (para que el link compartido siga funcionando).';

    public function handle(): int
    {
        $chamarra = Producto::query()
            ->where('precio_actual', '<', 100)
            ->whereRaw('LOWER(nombre) LIKE ?', ['%chamarra%'])
            ->first();

        if (! $chamarra) {
            $this->error('No se encontró ningún producto "chamarra" con precio menor a 100 pesos.');

            return self::FAILURE;
        }

        $this->info("Producto a conservar: #{$chamarra->id} - {$chamarra->nombre} - \${$chamarra->precio_actual}");
        $total = Producto::count();
        $this->warn("Se eliminarán " . ($total - 1) . " productos. Solo quedará la chamarra como oferta #1.");

        if (! $this->option('force') && ! $this->confirm('¿Continuar?')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($chamarra) {
            $row = $chamarra->getAttributes();
            foreach (['last_published_at', 'last_sent_telegram_at', 'created_at', 'updated_at'] as $key) {
                if (isset($row[$key]) && $row[$key] instanceof \DateTimeInterface) {
                    $row[$key] = $row[$key]->format('Y-m-d H:i:s');
                }
            }
            $row['id'] = 1;

            DB::table('producto_precio_historial')->delete();
            DB::table('productos')->delete();
            DB::statement('ALTER TABLE productos AUTO_INCREMENT = 1');
            DB::table('productos')->insert($row);
            DB::statement('ALTER TABLE productos AUTO_INCREMENT = 2');
        });

        $this->info('Listo. La chamarra queda como oferta #1. El link que compartiste (buscar 1) seguirá funcionando.');

        return self::SUCCESS;
    }
}
