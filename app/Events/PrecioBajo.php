<?php

namespace App\Events;

use App\Models\Producto;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dispara cuando un producto baja de precio (novedad con cambio en historial).
 * Flujo: Listener de monetización guarda URL afiliado → Listener de notificación encola EnviarOfertaTelegramJob.
 */
class PrecioBajo
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Producto $producto,
        public int $delaySegundos = 0
    ) {}
}
