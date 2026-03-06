<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rastreo de todas las tiendas (Sams, Costco, Amazon, Mercado Libre, Walmart, etc.) cada 30 minutos.
// Así las ofertas llegan de forma más constante (al menos cada media hora).
// withoutOverlapping(50): evita lanzar otro rastreo si el anterior sigue en curso o falló sin soltar el lock (máx 50 min).
// onOneServer(): en entornos con varios servidores, solo uno ejecuta la tarea.
Schedule::command('rastreo:todas')
    ->everyThirtyMinutes()
    ->withoutOverlapping(50)
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
