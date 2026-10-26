<?php

declare(strict_types=1);

namespace App\Actions\CuentasPorCobrar;

use App\Models\CuentaPorCobrar;
use App\Models\PagoCuenta;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AnularPagoCuenta
{
    public function ejecutar(PagoCuenta $pago, string $motivo, User $usuario): CuentaPorCobrar
    {
        return DB::transaction(function () use ($pago, $motivo, $usuario): CuentaPorCobrar {
            $pagoActual = PagoCuenta::query()
                ->whereKey($pago->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $cuenta = CuentaPorCobrar::query()
                ->whereKey($pagoActual->cuenta_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($pagoActual->anulado_at !== null) {
                throw ValidationException::withMessages([
                    'pago' => 'El pago ya fue anulado.',
                ]);
            }

            $motivo = trim($motivo);

            if (mb_strlen($motivo) < 3) {
                throw ValidationException::withMessages([
                    'motivo' => 'El motivo de anulación es obligatorio y debe tener al menos 3 caracteres.',
                ]);
            }

            $pagoActual->update([
                'anulado_at' => now(),
                'anulado_por' => $usuario->id,
                'motivo_anulacion' => $motivo,
            ]);

            $saldo = Dinero::sumar($cuenta->saldo, $pagoActual->monto);

            if (Dinero::mayorQue($saldo, $cuenta->monto_total)) {
                $saldo = Dinero::redondear($cuenta->monto_total);
            }

            $cuenta->saldo = $saldo;
            $cuenta->actualizarEstado();
            $cuenta->updated_by = $usuario->id;
            $cuenta->save();

            return $this->cargar($cuenta);
        });
    }

    private function cargar(CuentaPorCobrar $cuenta): CuentaPorCobrar
    {
        return $cuenta->load(
            'cliente',
            'orden',
            'pagos.usuario',
            'pagos.anulador',
            'creador',
            'editor',
        )->loadCount('pagos');
    }
}
