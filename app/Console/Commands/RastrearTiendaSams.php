<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RastreoTiendaComando;
use App\Services\CalculadoraOfertas;
use Illuminate\Console\Command;

class RastrearTiendaSams extends Command
{
    use RastreoTiendaComando;

    protected $signature = 'rastreo:tienda-sams
                            {--max= : Procesar solo los primeros N productos para agilizar}
                            {--notificar-todos : Encolar todas las ofertas con descuento (por defecto solo nuevas o actualizadas)}';

    protected $description = 'Rastrea ofertas de Sam\'s Club México';

    public function __construct(
        protected CalculadoraOfertas $calculadoraOfertas
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $max = $this->option('max') !== null ? (int) $this->option('max') : null;

        return $this->runRastreo('Sams Club', [
            'max' => $max,
            'notificar_todos' => (bool) $this->option('notificar-todos'),
        ]);
    }
}
