<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\CuentaPorCobrarController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\OrdenServicioController;
use App\Http\Controllers\Api\PagoCuentaController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProformaController;
use App\Http\Controllers\Api\ServicioController;
use App\Http\Controllers\Api\SolicitudController;
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
        Route::get('/clientes/{cliente}/resumen', [ClienteController::class, 'resumen']);
        Route::get('/clientes/{cliente}/servicios', [ClienteController::class, 'servicios']);
        Route::apiResource('productos', ProductoController::class);
        Route::apiResource('proformas', ProformaController::class);
        Route::apiResource('ordenes', OrdenServicioController::class)
            ->only(['index', 'show', 'store'])
            ->parameters(['ordenes' => 'orden']);
        Route::apiResource('servicios', ServicioController::class);
        Route::apiResource('solicitudes', SolicitudController::class)
            ->parameters(['solicitudes' => 'solicitud']);
        Route::apiResource('users', UserController::class);

        Route::get('/inventario', [InventarioController::class, 'index']);
        Route::get('/inventario/resumen', [InventarioController::class, 'resumen']);
        Route::get('/inventario/movimientos', [InventarioController::class, 'movimientos']);
        Route::post('/inventario/entradas', [InventarioController::class, 'entrada']);
        Route::post('/inventario/ajustes', [InventarioController::class, 'ajuste']);

        Route::post('/proformas/{proforma}/enviar', [ProformaController::class, 'enviar']);
        Route::post('/proformas/{proforma}/aceptar', [ProformaController::class, 'aceptar']);
        Route::post('/proformas/{proforma}/rechazar', [ProformaController::class, 'rechazar']);
        Route::get('/proformas/{proforma}/pdf', [ProformaController::class, 'pdf']);

        Route::post('/ordenes/{orden}/asignar', [OrdenServicioController::class, 'asignar']);
        Route::post('/ordenes/{orden}/iniciar', [OrdenServicioController::class, 'iniciar']);
        Route::post('/ordenes/{orden}/finalizar', [OrdenServicioController::class, 'finalizar']);
        Route::post('/ordenes/{orden}/cancelar', [OrdenServicioController::class, 'cancelar']);
        Route::post('/ordenes/{orden}/cerrar', [OrdenServicioController::class, 'cerrar']);
        Route::post('/ordenes/{orden}/consumir', [OrdenServicioController::class, 'consumir']);

        Route::get('/cuentas-por-cobrar', [CuentaPorCobrarController::class, 'index']);
        Route::get('/cuentas-por-cobrar/resumen', [CuentaPorCobrarController::class, 'resumen']);
        Route::get('/cuentas-por-cobrar/{cuenta}', [CuentaPorCobrarController::class, 'show']);
        Route::post('/cuentas-por-cobrar/{cuenta}/pagos', [CuentaPorCobrarController::class, 'registrarPago']);
        Route::post('/pagos-cuenta/{pago}/anular', [PagoCuentaController::class, 'anular']);

        Route::post('/solicitudes/{solicitud}/convertir', [SolicitudController::class, 'convertir']);
    });
});
