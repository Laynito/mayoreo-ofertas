<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rastreo de todas las tiendas (Calimax, Sams, Costco, Coppel, Elektra) cada hora.
// withoutOverlapping(120): si una ejecución tarda mucho, no se lanza otra hasta que termine o pasen 120 min.
// onOneServer(): en entornos con varios servidores, solo uno ejecuta la tarea.
Schedule::command('rastreo:todas')
    ->hourly()
    ->withoutOverlapping(120)
    ->onOneServer();

// Procesar bajadas de precio y enviar ofertas según calidad (Premium/Gratis) cada 5 minutos.
Schedule::command('ofertas:procesar-bajadas')
    ->everyFiveMinutes();

// Limpieza de capturas de Browsershot antiguas para no saturar el servidor.
Schedule::command('ofertas:limpiar-capturas')
    ->daily();

// Borra mensajes de oferta en Telegram con más de 24 h (deleteMessage) para mantener canales limpios.
Schedule::command('telegram:limpiar-mensajes-antiguos')
    ->daily();
