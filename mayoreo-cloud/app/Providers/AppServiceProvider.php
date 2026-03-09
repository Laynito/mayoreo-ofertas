<?php

namespace App\Providers;

use App\Console\Commands\MercadoLibreExchangeCodeCommand;
use App\Console\Commands\SyncProductosAffiliateCommand;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginResponseContract::class, \App\Http\Responses\Filament\LoginResponse::class);
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
