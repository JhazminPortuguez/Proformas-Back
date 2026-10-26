<?php

namespace App\Http\Controllers\Api;

use App\Actions\CuentasPorCobrar\AnularPagoCuenta;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnularPagoRequest;
use App\Http\Resources\CuentaPorCobrarResource;
use App\Models\PagoCuenta;

class PagoCuentaController extends Controller
{
    public function anular(
        AnularPagoRequest $request,
        PagoCuenta $pago,
        AnularPagoCuenta $anular,
    ) {
        $this->authorize('anular', $pago);

        $cuenta = $anular->ejecutar($pago, (string) $request->validated('motivo'), $request->user());

        return new CuentaPorCobrarResource($cuenta);
    }
}
