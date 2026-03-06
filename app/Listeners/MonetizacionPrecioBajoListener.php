<?php

namespace App\Listeners;

use App\Events\PrecioBajo;
use App\Services\AffiliateLinkService;

/**
 * Escucha PrecioBajo: pasa la URL del producto por AffiliateLinkService y guarda el enlace ya monetizado en url_afiliado.
 */
class MonetizacionPrecioBajoListener
{
    public function __construct(
        private readonly AffiliateLinkService $affiliateLinkService
    ) {}

    /**
     * Punto de entrada que Laravel usa para listeners (handle o __invoke).
     */
    public function handle(PrecioBajo $event): void
    {
        $producto = $event->producto;
        $base = $producto->url_original ?? $producto->affiliate_url ?? $producto->url_afiliado ?? '';
        if ($base === '' || ! str_starts_with($base, 'http')) {
            return;
        }

        $urlMonetizada = $this->affiliateLinkService->enlaceParaTelegram($base, $producto->tienda_origen ?? '');
        if ($urlMonetizada !== '') {
            $producto->url_afiliado = $urlMonetizada;
            $producto->saveQuietly();
        }
    }

    /**
     * Invocable: delega en handle() por si Laravel llama al listener como invocable.
     */
    public function __invoke(PrecioBajo $event): void
    {
        $this->handle($event);
    }
}
