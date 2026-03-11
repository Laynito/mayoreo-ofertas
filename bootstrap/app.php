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
        $schedule->command('app:run-scraper')->everyThirtyMinutes();
        $schedule->command('app:limpiar-ofertas')->dailyAt('03:00');
        $schedule->command('queue:work --stop-when-empty')->everyMinute();
        // Facebook: 1–3 publicaciones al día (horarios de buen engagement)
        $schedule->command('facebook:publish')->dailyAt('08:00');
        $schedule->command('facebook:publish')->dailyAt('13:00');
        $schedule->command('facebook:publish')->dailyAt('18:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
