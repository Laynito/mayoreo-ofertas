<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RastreoTiendaComando;
use App\Services\CalculadoraOfertas;
use Illuminate\Console\Command;

class RastrearTiendaCostco extends Command
{
    use RastreoTiendaComando;

    protected $signature = 'rastreo:tienda-costco
                            {--max= : Procesar solo los primeros N productos para agilizar}
                            {--notificar-todos : Encolar a Telegram todos con descuento real, no solo novedades}';

    protected $description = 'Rastrea ofertas de Costco México';

    public function __construct(
        protected CalculadoraOfertas $calculadoraOfertas
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $max = $this->option('max') !== null ? (int) $this->option('max') : null;

        return $this->runRastreo('Costco', [
            'max' => $max,
            'notificar_todos' => (bool) $this->option('notificar-todos'),
        ]);
    }
}
