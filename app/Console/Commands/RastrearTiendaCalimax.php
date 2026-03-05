<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RastreoTiendaComando;
use App\Services\CalculadoraOfertas;
use Illuminate\Console\Command;

class RastrearTiendaCalimax extends Command
{
    use RastreoTiendaComando;

    protected $signature = 'rastreo:tienda-calimax
                            {--max= : Procesar solo los primeros N productos para agilizar}
                            {--notificar-todos : Encolar todas las ofertas con descuento (por defecto solo nuevas o actualizadas)}';

    protected $description = 'Rastrea ofertas de Calimax (Tijuana / Baja California)';

    public function __construct(
        protected CalculadoraOfertas $calculadoraOfertas
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $max = $this->option('max') !== null ? (int) $this->option('max') : null;

        return $this->runRastreo('Calimax', [
            'max' => $max,
            'notificar_todos' => (bool) $this->option('notificar-todos'),
        ]);
    }
}
