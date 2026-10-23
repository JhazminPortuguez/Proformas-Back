<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ProformaController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('web');

Route::middleware(['web', 'auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::apiResource('clientes', ClienteController::class);
    Route::apiResource('productos', ProductoController::class);
    Route::apiResource('proformas', ProformaController::class);
    Route::apiResource('users', UserController::class)->middleware('can:manage-users');
});
