<?php

namespace App\Listeners;

use App\Events\PrecioBajo;
use App\Jobs\EnviarOfertaTelegramJob;

/**
 * Escucha PrecioBajo: encola EnviarOfertaTelegramJob con el producto (ya monetizado por MonetizacionPrecioBajoListener).
 */
class NotificacionPrecioBajoListener
{
    public function handle(PrecioBajo $event): void
    {
        $producto = $event->producto;
        $cola = in_array($producto->tienda_origen, ['Amazon', 'Mercado Libre'], true) ? 'high' : 'default';
        $job = EnviarOfertaTelegramJob::dispatch($producto)->onQueue($cola);
        if ($event->delaySegundos > 0) {
            $job->delay(now()->addSeconds($event->delaySegundos));
        }
    }
}
