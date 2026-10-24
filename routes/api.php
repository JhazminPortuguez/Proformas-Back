<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProformaController;
use App\Http\Controllers\Api\ServicioController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware(['web', 'throttle:register']);

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware(['web', 'throttle:login']);

        Route::middleware(['web', 'auth:sanctum', 'active'])->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'user']);
        });
    });

    Route::middleware(['web', 'auth:sanctum', 'active'])->group(function (): void {
        Route::get('/dashboard', DashboardController::class);

        Route::apiResource('clientes', ClienteController::class);
        Route::apiResource('productos', ProductoController::class);
        Route::apiResource('proformas', ProformaController::class);
        Route::apiResource('servicios', ServicioController::class);
        Route::apiResource('users', UserController::class);
    });
});
