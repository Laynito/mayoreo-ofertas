<?php

namespace App\Providers;

use App\Console\Commands\MercadoLibreExchangeCodeCommand;
use App\Console\Commands\SyncProductosAffiliateCommand;
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
            SyncProductosAffiliateCommand::class,
            MercadoLibreExchangeCodeCommand::class,
        ]);
    }
}
