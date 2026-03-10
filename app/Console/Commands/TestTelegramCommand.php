<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TestTelegramCommand extends Command
{
    protected $signature = 'test:telegram';

    protected $description = 'Envía un mensaje de prueba "Hola Mundo" a Telegram usando TelegramService';

    public function handle(TelegramService $telegram): int
    {
        $this->info('Enviando "Hola Mundo" a Telegram...');

        if ($telegram->sendText('Hola Mundo')) {
            $this->info('Mensaje enviado correctamente.');
            return self::SUCCESS;
        }

        $this->error('No se pudo enviar el mensaje. Revisa storage/logs/laravel.log');
        return self::FAILURE;
    }
}
