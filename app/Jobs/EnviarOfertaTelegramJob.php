<?php

namespace App\Jobs;

use App\Exceptions\TelegramRateLimitException;
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

    /** Tiempo suficiente para descargar imagen (CDN Coppel/Calimax hasta ~45s) y enviar o fallback a solo texto. */
    public int $timeout = 60;

    /** Si el modelo (Producto) fue eliminado antes de ejecutar el job, descartar sin marcar FAIL. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Producto $producto
    ) {}

    public function handle(NotificadorTelegram $notificador): void
    {
        try {
            $this->ejecutarNotificacion($notificador);
        } catch (TelegramRateLimitException $e) {
            Log::warning('EnviarOfertaTelegramJob: rate limit 429, reintentando en 30 segundos', [
                'producto_id' => $this->producto?->getKey(),
            ]);
            $this->release(30);
        } catch (\Throwable $e) {
            Log::error('EnviarOfertaTelegramJob: error no recuperado', [
                'producto_id' => $this->producto?->getKey(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    private function ejecutarNotificacion(NotificadorTelegram $notificador): void
    {
        $id = $this->producto?->getKey();
        if ($id === null) {
            return;
        }
        $producto = Producto::find($id);
        if ($producto === null) {
            return;
        }

        try {
            $notificador->notificarOferta($producto);
        } catch (TelegramRateLimitException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // No reenviar con enviarOfertaSoloTexto: podría haberse enviado ya (foto o texto) y causaría doble mensaje.
            Log::warning('EnviarOfertaTelegramJob: fallo en notificación', [
                'producto_id' => $producto->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
