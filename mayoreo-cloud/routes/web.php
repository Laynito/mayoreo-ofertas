<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
