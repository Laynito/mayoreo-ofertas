<?php

namespace App\Jobs;

use App\Exceptions\TelegramRateLimitException;
use App\Models\Configuracion;
use App\Models\NotificacionLog;
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

        if (config('app.debug')) {
            $chatId = Configuracion::getTelegramChatId();
            Log::info('Intentando enviar producto [' . ($producto->nombre ?? 'sin nombre') . '] al chat [' . ($chatId ?? 'no configurado') . ']');
        }

        try {
            $notificador->notificarOferta($producto);
        } catch (TelegramRateLimitException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $chatId = Configuracion::getTelegramChatId();
            NotificacionLog::registrar(
                $producto->id,
                $producto->tienda_origen,
                $chatId !== null ? (string) $chatId : null,
                NotificacionLog::ESTADO_FALLIDO,
                $e->getMessage(),
                null
            );
            Log::warning('EnviarOfertaTelegramJob: fallo en notificación', [
                'producto_id' => $producto->id,
                'error' => $e->getMessage(),
            ]);
            $this->enviarAlertaErrorATelegram($notificador, $producto, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Envía al canal de Telegram un mensaje de alerta cuando falla el envío de una oferta,
     * para que el administrador reciba los errores sin depender solo del log en BD.
     */
    private function enviarAlertaErrorATelegram(NotificadorTelegram $notificador, Producto $producto, string $mensajeError): void
    {
        try {
            $nombre = $producto->nombre !== null && $producto->nombre !== ''
                ? (strlen($producto->nombre) > 80 ? substr($producto->nombre, 0, 77) . '...' : $producto->nombre)
                : 'Producto #' . $producto->id;
            $texto = "⚠️ <b>Fallo envío oferta</b>\n\n"
                . "Producto: " . htmlspecialchars($nombre) . "\n"
                . "Tienda: " . htmlspecialchars($producto->tienda_origen ?? '—') . "\n"
                . "Error: " . htmlspecialchars(mb_substr($mensajeError, 0, 500));
            $notificador->enviarMensajeAlertaError($texto);
        } catch (\Throwable $e) {
            Log::warning('EnviarOfertaTelegramJob: no se pudo enviar alerta de error a Telegram', [
                'producto_id' => $producto->id,
                'error_alerta' => $e->getMessage(),
            ]);
        }
    }
}
