<?php

namespace App\Jobs;

use App\Models\Producto;
use App\Services\NotificadorTelegram;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnviarOfertaTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(
        public Producto $producto
    ) {}

    public function handle(NotificadorTelegram $notificador): void
    {
        $id = $this->producto?->getKey();
        if ($id === null) {
            return;
        }
        $producto = Producto::find($id);
        if ($producto === null) {
            return;
        }

        // Reparación de imágenes: Telegram da "Bad Request" si falta el protocolo
        if (! empty($producto->imagen_url)) {
            $url = $producto->imagen_url;
            if (! preg_match('#^https?://#i', $url)) {
                $producto->setAttribute('imagen_url', 'https://' . ltrim($url, '/:'));
            }
        }

        try {
            $notificador->notificarOferta($producto);
        } catch (\Throwable $e) {
            Log::warning('EnviarOfertaTelegramJob: fallo notificación, reenviando solo texto', [
                'producto_id' => $producto->id,
                'error' => $e->getMessage(),
            ]);
            try {
                $notificador->enviarOfertaSoloTexto($producto);
            } catch (\Throwable $e2) {
                Log::warning('EnviarOfertaTelegramJob: fallback solo texto también falló', [
                    'producto_id' => $producto->id,
                    'error' => $e2->getMessage(),
                ]);
            }
        }
    }
}
