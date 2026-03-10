<?php

namespace App\Console\Commands;

use App\Models\Producto;
use Illuminate\Console\Command;

class LimpiarProductosCaptchaCommand extends Command
{
    protected $signature = 'productos:limpiar-captcha
                            {--dry-run : Solo mostrar cuántos se borrarían, sin borrar}';

    protected $description = 'Borra productos Walmart con nombre "Verifica tu identidad" (páginas captcha)';

    public function handle(): int
    {
        $query = Producto::query()
            ->where('tienda', 'Walmart')
            ->where('nombre', 'like', '%Verifica tu identidad%');

        $count = $query->count();

        if ($count === 0) {
            $this->info('No hay productos a limpiar (captcha/precio 0).');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Se borrarían {$count} producto(s). Ejecuta sin --dry-run para borrar.");
            return self::SUCCESS;
        }

        $query->delete();
        $this->info("Borrados {$count} producto(s) con «Verifica tu identidad».");

        return self::SUCCESS;
    }
}
