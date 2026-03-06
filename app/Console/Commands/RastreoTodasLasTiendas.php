<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RastreoTiendaComando;
use App\Fabrica\RastreadorFabrica;
use App\Services\CalculadoraOfertas;
use Illuminate\Console\Command;

class RastreoTodasLasTiendas extends Command
{
    use RastreoTiendaComando;

    protected $signature = 'rastreo:todas
                            {--max= : Límite de productos por tienda (opcional)}
                            {--notificar-todos : Encolar todas las ofertas con descuento (por defecto solo nuevas o actualizadas)}
                            {--min-discount=10 : Descuento mínimo % para Mercado Libre (por defecto 10)}';

    protected $description = 'Rastrea ofertas de Sams Club, Costco, Elektra, Amazon, Mercado Libre, Walmart, etc.; al final de cada una se envía el resumen a Telegram';

    /** Tiendas a rastrear, en orden. Pausa de 10 s entre cada una para no saturar servidor ni APIs. */
    private const TIENDAS = ['Sams Club', 'Costco', 'Elektra', 'Amazon', 'Mercado Libre', 'Walmart', 'AliExpress', 'Office Depot'];

    public function __construct(
        protected CalculadoraOfertas $calculadoraOfertas
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $max = $this->option('max') !== null ? (int) $this->option('max') : null;
        $notificarTodos = (bool) $this->option('notificar-todos');
        $minDiscount = $this->option('min-discount') !== null ? (int) $this->option('min-discount') : 10;

        $this->info('Iniciando rastreo de todas las tiendas: ' . implode(', ', self::TIENDAS));

        $fallos = 0;
        $offsetOfertasGlobal = 0;
        foreach (self::TIENDAS as $tienda) {
            if (! RastreadorFabrica::tieneMotorPara($tienda)) {
                $this->warn("No hay motor para [{$tienda}], omitiendo.");
                $fallos++;

                continue;
            }

            $this->newLine();
            $this->info("——— Rastreando [{$tienda}] ———");

            $encoladosEstaTienda = 0;
            $codigo = $this->runRastreo($tienda, [
                'max' => $max,
                'notificar_todos' => $notificarTodos,
                'min_discount' => $minDiscount,
            ], $encoladosEstaTienda, $offsetOfertasGlobal);

            $offsetOfertasGlobal += $encoladosEstaTienda;

            if ($codigo !== 0) {
                $fallos++;
            }

            // Pausa entre tiendas para no saturar el servidor ni las APIs externas.
            if (array_key_last(self::TIENDAS) !== array_search($tienda, self::TIENDAS, true)) {
                sleep(10);
            }
        }

        $this->newLine();
        $this->info('Rastreo de todas las tiendas finalizado.');
        if ($fallos > 0) {
            $this->warn("Tiendas con fallo u omitidas: {$fallos}.");
        }
        $this->comment('Para que las ofertas lleguen a Telegram, el worker de cola debe estar corriendo (automático con Supervisor: ver docs/COLA-WORKER-AUTOMATIZAR.md). Revisa .env: TELEGRAM_CHAT_ID.');

        return $fallos > 0 ? 1 : 0;
    }
}
