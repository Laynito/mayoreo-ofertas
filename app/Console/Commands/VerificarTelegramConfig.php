<?php

namespace App\Console\Commands;

use App\Models\Configuracion;
use Illuminate\Console\Command;

/**
 * Comprueba que token y canal de Telegram (TELEGRAM_CHAT_ID) estén configurados para recibir ofertas.
 */
class VerificarTelegramConfig extends Command
{
    protected $signature = 'telegram:verificar
                            {--test : Envía un mensaje de prueba al canal configurado}';

    protected $description = 'Verifica TELEGRAM_BOT_TOKEN y TELEGRAM_CHAT_ID; opcionalmente envía mensaje de prueba';

    public function handle(): int
    {
        $token = Configuracion::getTelegramToken();
        $chatId = Configuracion::getTelegramChatId();

        $this->info('--- Configuración Telegram ---');
        $this->line('TELEGRAM_BOT_TOKEN: ' . ($token ? '✓ definido (' . strlen($token) . ' caracteres)' : '✗ vacío o no definido'));
        $this->line('TELEGRAM_CHAT_ID (canal principal): ' . ($chatId ? "✓ {$chatId}" : '✗ vacío'));

        if (empty($token)) {
            $this->error('Configura TELEGRAM_BOT_TOKEN en Ajustes (Sistema) o en el .env y ejecuta: php artisan config:clear');
            return self::FAILURE;
        }

        if (empty($chatId)) {
            $this->warn('TELEGRAM_CHAT_ID no está definido en Ajustes ni en .env. Las ofertas no se enviarán a ningún sitio.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Si no llegan ofertas: 1) php artisan config:clear  2) Reinicia el worker: sudo supervisorctl restart mayoreo-worker:*');

        if (! $this->option('test')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $res = \Illuminate\Support\Facades\Http::withOptions(['verify' => false])
            ->timeout(10)
            ->asForm()
            ->post($url, [
                'chat_id' => $chatId,
                'text' => '🧪 Prueba desde Mayoreo Cloud. Si ves esto, el bot y el canal están bien.',
                'parse_mode' => 'HTML',
            ]);
        if ($res->successful()) {
            $this->info('Mensaje de prueba enviado al canal. Revisa Telegram.');
        } else {
            $this->error('Fallo al enviar: ' . $res->body());
        }

        return self::SUCCESS;
    }
}
