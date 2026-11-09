<?php

declare(strict_types=1);

namespace App\Actions\CuentasPorCobrar;

use App\Enums\EstadoCobro;
use App\Models\CuentaPorCobrar;
use App\Models\User;
use App\Support\Dinero;
use App\Support\FechaBolivia;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegistrarPagoCuenta
{
    /**
     * @param  array{monto: float|int|string, metodo: string, fecha_pago?: string|null, referencia?: string|null, observaciones?: string|null}  $datos
     */
    public function ejecutar(CuentaPorCobrar $cuenta, array $datos, User $usuario): CuentaPorCobrar
    {
        return DB::transaction(function () use ($cuenta, $datos, $usuario): CuentaPorCobrar {
            $actual = CuentaPorCobrar::query()
                ->whereKey($cuenta->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $monto = Dinero::redondear($datos['monto']);

            if (Dinero::comparar($monto, 0) <= 0) {
                throw ValidationException::withMessages([
                    'monto' => 'El monto del pago debe ser mayor que cero.',
                ]);
            }

            if ($actual->estado_cobro === EstadoCobro::Pagada || Dinero::esCero($actual->saldo)) {
                throw ValidationException::withMessages([
                    'monto' => 'La cuenta ya está pagada.',
                ]);
            }

            if (Dinero::mayorQue($monto, $actual->saldo)) {
                throw ValidationException::withMessages([
                    'monto' => "El monto del pago no puede superar el saldo pendiente ({$actual->saldo}).",
                ]);
            }

            $fechaPago = $this->validarFechaPago($actual, $datos['fecha_pago'] ?? null);

            $actual->pagos()->create([
                'empresa_id' => $actual->empresa_id,
                'monto' => $monto,
                'metodo' => $datos['metodo'],
                'fecha_pago' => $fechaPago,
                'referencia' => $datos['referencia'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'created_by' => $usuario->id,
            ]);

            $saldo = Dinero::restar($actual->saldo, $monto);

            $actual->update([
                'saldo' => Dinero::comparar($saldo, 0) < 0 ? Dinero::redondear(0) : $saldo,
                'estado_cobro' => $this->estadoParaSaldo($saldo),
                'updated_by' => $usuario->id,
            ]);

            return $this->cargar($actual);
        });
    }

    /**
     * Sin fecha, el pago es de hoy (Bolivia). No se aceptan pagos futuros ni
     * anteriores a la emisión de la cuenta.
     */
    private function validarFechaPago(CuentaPorCobrar $cuenta, ?string $fecha): string
    {
        $hoy = FechaBolivia::hoyTexto();

        if ($fecha === null || trim($fecha) === '') {
            return $hoy;
        }

        $fechaPago = CarbonImmutable::parse($fecha)->toDateString();

        if ($fechaPago > $hoy) {
            throw ValidationException::withMessages([
                'fecha_pago' => 'La fecha de pago no puede ser futura (hoy es '.FechaBolivia::formato($hoy).').',
            ]);
        }

        $emision = $cuenta->fecha_emision?->toDateString();

        if ($emision !== null && $fechaPago < $emision) {
            throw ValidationException::withMessages([
                'fecha_pago' => 'La fecha de pago no puede ser anterior a la emisión de la cuenta ('.FechaBolivia::formato($emision).').',
            ]);
        }

        return $fechaPago;
    }

    private function estadoParaSaldo(string $saldo): EstadoCobro
    {
        return Dinero::esCero($saldo) ? EstadoCobro::Pagada : EstadoCobro::Parcial;
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
