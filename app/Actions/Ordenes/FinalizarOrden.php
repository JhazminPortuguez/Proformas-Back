<?php

declare(strict_types=1);

namespace App\Actions\Ordenes;

use App\Enums\EstadoOrden;
use App\Enums\TipoOrdenEvento;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinalizarOrden
{
    public function ejecutar(OrdenServicio $orden, User $usuario, ?string $observaciones = null): OrdenServicio
    {
        return DB::transaction(function () use ($orden, $usuario, $observaciones): OrdenServicio {
            $actual = OrdenServicio::query()
                ->whereKey($orden->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado !== EstadoOrden::EnProceso) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede finalizar una orden en proceso.',
                ]);
            }

            $detalle = $this->limpiar($observaciones);

            $cambios = [
                'estado' => EstadoOrden::Finalizada,
                'fecha_fin' => now(),
                'updated_by' => $usuario->id,
            ];

            if ($detalle !== null) {
                $cambios['observaciones'] = $detalle;
            }

            $actual->update($cambios);

            $actual->eventos()->create([
                'tipo' => TipoOrdenEvento::Finalizacion,
                'descripcion' => 'Orden finalizada.',
                'estado_anterior' => EstadoOrden::EnProceso,
                'estado_nuevo' => EstadoOrden::Finalizada,
                'user_id' => $usuario->id,
                'metadata' => $detalle === null ? null : ['observaciones' => $detalle],
            ]);

            return $actual->load('cliente', 'proforma', 'operario', 'items', 'eventos.usuario')
                ->loadCount('items');
        });
    }

    private function limpiar(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }
}
