<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Scraper cada 10 min para que haya ofertas nuevas con esa frecuencia (ML, Coppel, Walmart)
        $schedule->command('app:run-scraper')->everyTenMinutes()->withoutOverlapping(15);
        $schedule->command('productos:sync-affiliate --send-telegram')->everyTenMinutes();
        // Ofertas variadas cada 10 min (productos ya con enlace que no se enviaron en las últimas 12 h)
        $schedule->command('telegram:send-varied')->everyTenMinutes();
        // app:limpiar-ofertas desactivado: borraba todos los productos cada madrugada
        // $schedule->command('app:limpiar-ofertas')->dailyAt('03:00');
        $schedule->command('queue:work --stop-when-empty')->everyMinute();
        // Facebook: 1–3 publicaciones al día (horarios de buen engagement)
        $schedule->command('facebook:publish')->dailyAt('08:00');
        $schedule->command('facebook:publish')->dailyAt('13:00');
        $schedule->command('facebook:publish')->dailyAt('18:00');
        // Eliminar publicaciones viejas sin interacción (cada semana, domingo 04:00)
        $schedule->command('facebook:cleanup-old-posts --days=14 --force')->weeklyOn(0, '04:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
