<?php

declare(strict_types=1);

namespace App\Actions\CuentasPorCobrar;

use App\Enums\EstadoCobro;
use App\Enums\EstadoOrden;
use App\Models\CuentaPorCobrar;
use App\Models\OrdenServicio;
use App\Models\Proforma;
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

            // Se cobra lo que el cliente aceptó: el total de la proforma
            // (subtotal - descuento + impuesto) y en su moneda. La proforma
            // aceptada y los ítems de la orden ya no se pueden modificar.
            $proforma = Proforma::query()->findOrFail($actual->proforma_id);
            $montoTotal = Dinero::redondear($proforma->total);
            $fechaEmision = $actual->cerrada_at->toDateString();
            $fechaVencimiento = $actual->cerrada_at->copy()->addDays(30)->toDateString();

            $cuenta = CuentaPorCobrar::create([
                'empresa_id' => $actual->empresa_id,
                'orden_id' => $actual->getKey(),
                'cliente_id' => $actual->cliente_id,
                'numero' => $this->numeros->ejecutar($actual->cerrada_at),
                'fecha_emision' => $fechaEmision,
                'fecha_vencimiento' => $fechaVencimiento,
                'moneda' => $proforma->moneda,
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
