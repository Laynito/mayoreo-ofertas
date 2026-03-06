<?php

namespace App\Console\Commands;

use App\Models\TelegramMensajeOferta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Borra de Telegram los mensajes de oferta con más de 24 horas.
 * Usa deleteMessage para que el canal Premium (y Gratis) solo muestre ofertas vigentes del día.
 */
class LimpiarMensajesTelegramOfertas extends Command
{
    protected $signature = 'telegram:limpiar-mensajes-antiguos
                            {--horas=24 : Mensajes con más de esta antigüedad (en horas) se borran}';

    protected $description = 'Borra mensajes de oferta en Telegram con más de 24 h (deleteMessage) y limpia la tabla';

    public function handle(): int
    {
        $token = config('services.telegram.token');
        if (empty($token)) {
            $this->warn('TELEGRAM_BOT_TOKEN no configurado. No se puede borrar mensajes.');
            return self::FAILURE;
        }

        $horas = (int) $this->option('horas');
        $horas = $horas > 0 ? $horas : 24;
        $limite = now()->subHours($horas);

        $mensajes = TelegramMensajeOferta::query()
            ->where('enviado_at', '<', $limite)
            ->orderBy('id')
            ->get();

        if ($mensajes->isEmpty()) {
            $this->info('No hay mensajes de oferta con más de ' . $horas . ' horas.');
            return self::SUCCESS;
        }

        $urlBase = "https://api.telegram.org/bot{$token}/deleteMessage";
        $borrados = 0;
        $errores = 0;

        foreach ($mensajes as $mensaje) {
            $response = Http::withOptions(['verify' => false])
                ->timeout(10)
                ->asForm()
                ->post($urlBase, [
                    'chat_id' => $mensaje->chat_id,
                    'message_id' => $mensaje->message_id,
                ]);

            if ($response->successful() && ($response->json('ok') === true)) {
                $borrados++;
            } else {
                $errores++;
                Log::debug('Telegram deleteMessage fallido', [
                    'chat_id' => $mensaje->chat_id,
                    'message_id' => $mensaje->message_id,
                    'body' => $response->body(),
                ]);
            }

            $mensaje->delete();
        }

        $this->info("Mensajes antiguos: {$borrados} borrados en Telegram, {$errores} fallos (registros eliminados de la BD).");
        return self::SUCCESS;
    }
}
