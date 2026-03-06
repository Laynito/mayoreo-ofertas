<?php

use App\Http\Controllers\MercadoLibreAuthController;
use App\Http\Controllers\MercadoLibreNotificationsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('ofertas');
})->name('home');

// Mercado Libre OAuth: autorizar app y recibir tokens
Route::get('/mercado-libre/login', [MercadoLibreAuthController::class, 'login'])->name('mercado-libre.login');
Route::get('/mercado-libre/callback', [MercadoLibreAuthController::class, 'callback'])->name('mercado-libre.callback');

// Notificaciones de Mercado Libre (webhook). Configurar en panel ML: https://mayoreo.cloud/api/mercado-libre/notifications
Route::post('/api/mercado-libre/notifications', [MercadoLibreNotificationsController::class, 'handle'])->name('mercado-libre.notifications');

Route::get('/welcome', function () {
    return view('welcome');
});

// Este sitio es Laravel, no WordPress; enlaces antiguos a wp-admin devuelven 404
Route::any('/wp-admin', fn () => abort(404))->name('wp-admin');
Route::any('/wp-admin/{any}', fn () => abort(404))->where('any', '.*');
