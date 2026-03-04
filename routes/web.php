<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('ofertas');
})->name('home');

Route::get('/welcome', function () {
    return view('welcome');
});

// Este sitio es Laravel, no WordPress; enlaces antiguos a wp-admin devuelven 404
Route::any('/wp-admin', fn () => abort(404))->name('wp-admin');
Route::any('/wp-admin/{any}', fn () => abort(404))->where('any', '.*');
