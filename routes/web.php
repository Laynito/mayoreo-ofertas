<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\OfertasController;
use App\Http\Controllers\PreciosBajosController;
use Illuminate\Support\Facades\Route;

// Público (sin registro): ofertas para TikTok / link en bio
Route::get('/', [OfertasController::class, 'index'])->name('ofertas.dia');
Route::get('/ofertas', [OfertasController::class, 'index']);
Route::get('/ofertas/todas', [OfertasController::class, 'todas'])->name('ofertas.todas');

// Páginas legales (TikTok for Developers: Terms of Service, Privacy Policy)
Route::get('/terminos', fn () => view('legal.terminos'))->name('terminos');
Route::get('/aviso-de-privacidad', fn () => view('legal.aviso-privacidad'))->name('aviso-privacidad');

// Tracking de clicks: redirige al afiliado y registra (métricas ML, Coppel, etc.)
Route::get('/out/{producto}', \App\Http\Controllers\OutController::class)->name('out');

Route::get('/welcome', function () {
    return view('welcome');
});

// Login de miembros (candado) — front distinto al admin
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [LoginController::class, 'login']);
});
Route::post('logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Zona de precios bajos (solo miembros autenticados)
Route::get('precios-bajos', PreciosBajosController::class)
    ->middleware('auth')
    ->name('precios-bajos');

// Callback de OAuth Mercado Libre: ML redirige aquí con ?code=... tras autorizar
Route::get('/mercado-libre/callback', function () {
    $code = request('code');
    $error = request('error');

    if ($error) {
        return response()->view('mercado-libre-callback', [
            'error' => $error,
            'description' => request('error_description', 'Error de autorización'),
        ], 400);
    }

    if (! $code) {
        return response()->view('mercado-libre-callback', [
            'error' => 'missing_code',
            'description' => 'No se recibió el parámetro code en la URL.',
        ], 400);
    }

    return response()->view('mercado-libre-callback', [
        'code' => $code,
        'redirect_uri' => config('services.mercadolibre.redirect_uri'),
    ]);
})->name('mercado-libre.callback');
