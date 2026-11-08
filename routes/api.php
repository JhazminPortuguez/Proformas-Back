<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\CuentaPorCobrarController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmpresaController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\OrdenServicioController;
use App\Http\Controllers\Api\PagoCuentaController;
use App\Http\Controllers\Api\Portal\PortalCatalogoController;
use App\Http\Controllers\Api\Portal\PortalInicioController;
use App\Http\Controllers\Api\Portal\PortalProformaController;
use App\Http\Controllers\Api\Portal\PortalSolicitudController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProformaController;
use App\Http\Controllers\Api\ReporteController;
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

        // Configuración de la empresa: solo el administrador.
        Route::get('/empresa', [EmpresaController::class, 'show']);
        Route::put('/empresa', [EmpresaController::class, 'update']);
        Route::get('/empresa/logo', [EmpresaController::class, 'logo']);
        Route::post('/empresa/logo', [EmpresaController::class, 'subirLogo']);
        Route::delete('/empresa/logo', [EmpresaController::class, 'eliminarLogo']);

        Route::prefix('reportes')->group(function (): void {
            Route::get('/ordenes', [ReporteController::class, 'ordenes']);
            Route::get('/servicios', [ReporteController::class, 'serviciosProformas']);
            Route::get('/inventario', [ReporteController::class, 'inventario']);
            Route::get('/cobros', [ReporteController::class, 'cobros']);
            Route::get('/clientes', [ReporteController::class, 'clientes']);
        });

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
        Route::post('/ordenes/{orden}/reprogramar', [OrdenServicioController::class, 'reprogramar']);
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

    // Portal del cliente: cada consulta se filtra por el cliente_id del usuario
    // de la sesión. Un registro de otro cliente responde 404.
    Route::prefix('portal')
        ->middleware(['web', 'auth:sanctum', 'active', 'cliente'])
        ->group(function (): void {
            Route::get('/resumen', [PortalInicioController::class, 'resumen']);
            Route::get('/empresa', [PortalInicioController::class, 'empresa']);

            Route::get('/servicios', [PortalCatalogoController::class, 'index']);
            Route::get('/servicios/{id}', [PortalCatalogoController::class, 'show'])->whereNumber('id');

            Route::get('/solicitudes', [PortalSolicitudController::class, 'index']);
            Route::post('/solicitudes', [PortalSolicitudController::class, 'store'])
                ->middleware('throttle:portal-solicitudes');
            Route::get('/solicitudes/{id}', [PortalSolicitudController::class, 'show'])->whereNumber('id');

            Route::get('/proformas', [PortalProformaController::class, 'index']);
            Route::get('/proformas/{id}', [PortalProformaController::class, 'show'])->whereNumber('id');
            Route::get('/proformas/{id}/pdf', [PortalProformaController::class, 'pdf'])->whereNumber('id');
        });
});
