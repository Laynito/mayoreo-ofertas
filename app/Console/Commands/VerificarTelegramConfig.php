<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Comprueba que token y canales de Telegram estén configurados para recibir ofertas.
 */
class VerificarTelegramConfig extends Command
{
    protected $signature = 'telegram:verificar
                            {--test : Envía un mensaje de prueba a cada canal configurado}';

    protected $description = 'Verifica TELEGRAM_BOT_TOKEN y canales Free/Premium; opcionalmente envía mensaje de prueba';

    public function handle(): int
    {
        $token = config('services.telegram.token');
        $chatFree = config('services.telegram.chat_id_free');
        $chatPremium = config('services.telegram.chat_id_premium');
        $chatFallback = config('services.telegram.chat_id');

        $this->info('--- Configuración Telegram ---');
        $this->line('TELEGRAM_BOT_TOKEN: ' . ($token ? '✓ definido (' . strlen($token) . ' caracteres)' : '✗ vacío o no definido'));
        $this->line('TELEGRAM_CHAT_ID_FREE (canal Gratis): ' . ($chatFree ? "✓ {$chatFree}" : '✗ vacío'));
        $this->line('TELEGRAM_CHAT_ID_PREMIUM (canal Premium): ' . ($chatPremium ? "✓ {$chatPremium}" : '✗ vacío'));
        $this->line('TELEGRAM_CHAT_ID (fallback): ' . ($chatFallback ? "✓ {$chatFallback}" : 'vacío'));

        if (empty($token)) {
            $this->error('Configura TELEGRAM_BOT_TOKEN en el .env y ejecuta: php artisan config:clear');
            return self::FAILURE;
        }

        $alMenosUno = ($chatFree !== null && $chatFree !== '') || ($chatPremium !== null && $chatPremium !== '');
        if (! $alMenosUno && empty($chatFallback)) {
            $this->warn('Ni Free ni Premium ni CHAT_ID están definidos. Las ofertas no se enviarán a ningún sitio.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Si no llegan ofertas: 1) php artisan config:clear  2) Reinicia el worker: sudo supervisorctl restart mayoreo-worker:*');

        if (! $this->option('test')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $enviado = 0;
        foreach (['Free' => $chatFree, 'Premium' => $chatPremium] as $nombre => $chatId) {
            if (empty($chatId)) {
                continue;
            }
            $url = "https://api.telegram.org/bot{$token}/sendMessage";
            $res = \Illuminate\Support\Facades\Http::withOptions(['verify' => false])
                ->timeout(10)
                ->asForm()
                ->post($url, [
                    'chat_id' => $chatId,
                    'text' => "🧪 Prueba desde Mayoreo Cloud – canal {$nombre}. Si ves esto, el bot y el canal están bien.",
                    'parse_mode' => 'HTML',
                ]);
            if ($res->successful()) {
                $this->info("Mensaje de prueba enviado al canal {$nombre}.");
                $enviado++;
            } else {
                $this->error("Fallo al enviar al canal {$nombre}: " . $res->body());
            }
        }

        if ($enviado > 0) {
            $this->info("Revisa Telegram: deberías ver {$enviado} mensaje(s) de prueba.");
        }

        return self::SUCCESS;
    }
}
