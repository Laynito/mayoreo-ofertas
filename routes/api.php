<?php

use App\Http\Controllers\FacebookFeedController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// Feed de productos para Meta Commerce Manager (Facebook / Instagram Shops).
// En Commerce Manager → Catálogos → Agregar fuente → Lista de datos programada → URL: https://tu-dominio.com/api/facebook-feed
Route::get('/facebook-feed', FacebookFeedController::class)->name('api.facebook-feed');

// Callback de notificaciones de Mercado Libre (webhook).
// ML envía POST aquí; hay que responder 200 en < 500 ms.
Route::post('/mercado-libre/notifications', function (Request $request) {
    $payload = $request->all();
    Log::channel('stack')->info('Mercado Libre notification', [
        'payload' => $payload,
        'headers' => $request->headers->all(),
    ]);
    return response()->json(['received' => true], 200);
})->name('api.mercado-libre.notifications');
