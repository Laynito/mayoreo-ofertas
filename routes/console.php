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
