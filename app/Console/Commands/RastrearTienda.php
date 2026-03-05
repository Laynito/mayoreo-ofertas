<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RastreoTiendaComando;
use App\Services\CalculadoraOfertas;
use Illuminate\Console\Command;

class RastrearTienda extends Command
{
    use RastreoTiendaComando;

    protected $signature = 'rastreo:tienda
                            {tienda : Nombre de la tienda (ej. Coppel, Walmart)}
                            {--max= : Procesar solo los primeros N productos (ej. 10 o 20) para agilizar}
                            {--notificar-todos : Encolar todas las ofertas con descuento (por defecto solo nuevas o actualizadas)}';

    protected $description = 'Rastrea ofertas de una tienda, actualiza productos y registra historial de precios';

    public function __construct(
        protected CalculadoraOfertas $calculadoraOfertas
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tiendaArgumento = $this->argument('tienda');
        $max = $this->option('max') !== null ? (int) $this->option('max') : null;

        return $this->runRastreo($tiendaArgumento, [
            'max' => $max,
            'notificar_todos' => (bool) $this->option('notificar-todos'),
        ]);
    }
}
