<?php

namespace App\Providers;

use App\Console\Commands\AdmitadCouponsCommand;
use App\Console\Commands\AdmitadProgramsCommand;
use App\Console\Commands\FullBackupCommand;
use App\Console\Commands\MercadoLibreExchangeCodeCommand;
use App\Console\Commands\SyncProductosAffiliateCommand;
use App\Models\Marketplace;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Support\Facades\View;
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
            FullBackupCommand::class,
            SyncProductosAffiliateCommand::class,
            MercadoLibreExchangeCodeCommand::class,
            AdmitadProgramsCommand::class,
            AdmitadCouponsCommand::class,
        ]);

        View::composer('layouts.front', function ($view): void {
            $code = Marketplace::query()
                ->where('es_activo', true)
                ->whereNotNull('verification_code')
                ->where('verification_code', '!=', '')
                ->value('verification_code');
            $view->with('verification_meta', $code ?? '');
        });
    }
}
