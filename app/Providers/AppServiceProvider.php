<?php

namespace App\Providers;

use App\Console\Commands\RastrearTienda;
use App\Console\Commands\RastrearTiendaCalimax;
use App\Console\Commands\RastrearTiendaCostco;
use App\Console\Commands\RastrearTiendaSams;
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
    }
}
