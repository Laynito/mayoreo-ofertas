<?php

namespace App\Providers;

use App\Console\Commands\RastrearTienda;
use App\Console\Commands\RastrearTiendaCalimax;
use App\Console\Commands\RastrearTiendaCostco;
use App\Console\Commands\RastrearTiendaSams;
use App\Events\PrecioBajo;
use App\Listeners\MonetizacionPrecioBajoListener;
use App\Listeners\NotificacionPrecioBajoListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->commands([
            RastrearTienda::class,
            RastrearTiendaCalimax::class,
            RastrearTiendaSams::class,
            RastrearTiendaCostco::class,
        ]);

        Event::listen(PrecioBajo::class, [
            MonetizacionPrecioBajoListener::class,
            NotificacionPrecioBajoListener::class,
        ]);
    }
}
