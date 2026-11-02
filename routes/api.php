<?php

use App\Http\Controllers\Api\ObservacionesAbiertasController;
use Illuminate\Support\Facades\Route;

/*
 * API de solo lectura para sistemas externos, autenticada con token Bearer
 * (Sanctum). Los tokens los emite `php artisan api:token-crear`.
 *
 * - `auth:sanctum` + `abilities:observaciones:leer`: el token tiene que existir,
 *   no estar vencido y traer esa ability. Un token futuro para otra cosa no lee
 *   esto por accidente.
 * - `throttle:60,1`: tope por minuto, por token.
 */
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'abilities:observaciones:leer', 'throttle:60,1'])
    ->group(function () {
        Route::get('clientes-con-observaciones', [ObservacionesAbiertasController::class, 'clientes'])
            ->name('api.clientes-con-observaciones');

        Route::get('clientes/{numero}/observaciones', [ObservacionesAbiertasController::class, 'observaciones'])
            ->name('api.clientes.observaciones');
    });
