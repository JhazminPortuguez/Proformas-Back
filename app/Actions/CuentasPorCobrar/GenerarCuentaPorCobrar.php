<?php

declare(strict_types=1);

namespace App\Actions\CuentasPorCobrar;

use App\Enums\EstadoCobro;
use App\Enums\EstadoOrden;
use App\Models\CuentaPorCobrar;
use App\Models\OrdenItem;
use App\Models\OrdenServicio;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerarCuentaPorCobrar
{
    public function __construct(
        private readonly GenerarNumeroCuenta $numeros,
    ) {}

    public function ejecutar(OrdenServicio $orden, User $usuario): CuentaPorCobrar
    {
        return DB::transaction(function () use ($orden, $usuario): CuentaPorCobrar {
            $actual = OrdenServicio::query()
                ->whereKey($orden->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existente = CuentaPorCobrar::query()
                ->where('orden_id', $actual->getKey())
                ->first();

            if ($existente !== null) {
                return $this->cargar($existente);
            }

            if ($actual->cerrada_at === null || $actual->estado !== EstadoOrden::Finalizada) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede generar una cuenta por cobrar para una orden cerrada y finalizada.',
                ]);
            }

            $montoTotal = $this->sumarLineas($actual);
            $fechaEmision = $actual->cerrada_at->toDateString();
            $fechaVencimiento = $actual->cerrada_at->copy()->addDays(30)->toDateString();

            $cuenta = CuentaPorCobrar::create([
                'empresa_id' => $actual->empresa_id,
                'orden_id' => $actual->getKey(),
                'cliente_id' => $actual->cliente_id,
                'numero' => $this->numeros->ejecutar($actual->cerrada_at),
                'fecha_emision' => $fechaEmision,
                'fecha_vencimiento' => $fechaVencimiento,
                'moneda' => 'BOB',
                'monto_total' => $montoTotal,
                'saldo' => $montoTotal,
                'estado_cobro' => Dinero::esCero($montoTotal)
                    ? EstadoCobro::Pagada
                    : EstadoCobro::Pendiente,
                'created_by' => $usuario->id,
                'updated_by' => $usuario->id,
            ]);

            return $this->cargar($cuenta);
        });
    }

    private function sumarLineas(OrdenServicio $orden): string
    {
        $lineas = OrdenItem::query()
            ->where('orden_id', $orden->getKey())
            ->pluck('total_linea')
            ->all();

        return Dinero::sumar(...$lineas);
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
