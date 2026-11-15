<?php

declare(strict_types=1);

namespace App\Actions\CuentasPorCobrar;

use App\Enums\EstadoCobro;
use App\Models\CuentaPorCobrar;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * X-11: anular una cuenta por cobrar. Solo si no tiene pagos vigentes (saldo
 * igual al monto total); si los tiene, primero se anulan los pagos. La
 * cuenta queda ANULADA con saldo 0 y deja de sumar en el dashboard y en los
 * reportes de cobranza. No hay reembolsos.
 */
final class AnularCuentaPorCobrar
{
    public const MENSAJE_CON_PAGOS = 'La cuenta tiene pagos vigentes. Anula primero los pagos y luego la cuenta.';

    public function ejecutar(CuentaPorCobrar $cuenta, string $motivo, User $usuario): CuentaPorCobrar
    {
        return DB::transaction(function () use ($cuenta, $motivo, $usuario): CuentaPorCobrar {
            $actual = CuentaPorCobrar::query()
                ->whereKey($cuenta->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado_cobro === EstadoCobro::Anulada) {
                throw ValidationException::withMessages([
                    'estado' => 'La cuenta ya está anulada.',
                ]);
            }

            $motivo = trim($motivo);

            if ($motivo === '') {
                throw ValidationException::withMessages([
                    'motivo' => 'El motivo de la anulación es obligatorio.',
                ]);
            }

            $tienePagos = $actual->pagosVigentes()->exists()
                || Dinero::comparar($actual->saldo, $actual->monto_total) !== 0;

            if ($tienePagos) {
                throw ValidationException::withMessages([
                    'estado' => self::MENSAJE_CON_PAGOS,
                ]);
            }

            $actual->update([
                'estado_cobro' => EstadoCobro::Anulada,
                'saldo' => Dinero::redondear(0),
                'anulada_at' => now(),
                'anulada_por' => $usuario->id,
                'motivo_anulacion' => $motivo,
                'updated_by' => $usuario->id,
            ]);

            return $actual->load('cliente', 'orden', 'pagos.usuario', 'pagos.anulador', 'creador', 'editor', 'anulador')
                ->loadCount('pagos');
        });
    }
}
