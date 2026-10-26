<?php

declare(strict_types=1);

namespace App\Actions\Ordenes;

use App\Actions\CuentasPorCobrar\GenerarCuentaPorCobrar;
use App\Enums\EstadoOrden;
use App\Enums\TipoOrdenEvento;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CerrarOrden
{
    public function __construct(
        private readonly GenerarCuentaPorCobrar $generarCuenta,
    ) {}

    public function ejecutar(OrdenServicio $orden, User $usuario): OrdenServicio
    {
        return DB::transaction(function () use ($orden, $usuario): OrdenServicio {
            $actual = OrdenServicio::query()
                ->whereKey($orden->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado !== EstadoOrden::Finalizada) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede cerrar una orden finalizada.',
                ]);
            }

            if ($actual->cerrada_at !== null) {
                throw ValidationException::withMessages([
                    'estado' => 'La orden ya fue cerrada.',
                ]);
            }

            $actual->update([
                'cerrada_at' => now(),
                'cerrada_por' => $usuario->id,
                'updated_by' => $usuario->id,
            ]);

            $actual->eventos()->create([
                'tipo' => TipoOrdenEvento::Cierre,
                'descripcion' => 'Orden cerrada.',
                'estado_anterior' => EstadoOrden::Finalizada,
                'estado_nuevo' => null,
                'user_id' => $usuario->id,
            ]);

            $this->generarCuenta->ejecutar($actual, $usuario);

            return $actual->load('cliente', 'proforma', 'operario', 'items', 'eventos.usuario', 'cerrador', 'cuentaPorCobrar')
                ->loadCount('items');
        });
    }
}
