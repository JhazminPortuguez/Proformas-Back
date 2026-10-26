<?php

declare(strict_types=1);

namespace App\Actions\Ordenes;

use App\Enums\EstadoOrden;
use App\Enums\TipoOrdenEvento;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IniciarOrden
{
    public function ejecutar(OrdenServicio $orden, User $usuario, ?string $observaciones = null): OrdenServicio
    {
        return DB::transaction(function () use ($orden, $usuario, $observaciones): OrdenServicio {
            $actual = OrdenServicio::query()
                ->whereKey($orden->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado !== EstadoOrden::Asignada) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede iniciar una orden asignada.',
                ]);
            }

            $actual->update([
                'estado' => EstadoOrden::EnProceso,
                'fecha_inicio' => now(),
                'updated_by' => $usuario->id,
            ]);

            $detalle = $this->limpiar($observaciones);

            $actual->eventos()->create([
                'tipo' => TipoOrdenEvento::Inicio,
                'descripcion' => 'Orden iniciada.',
                'estado_anterior' => EstadoOrden::Asignada,
                'estado_nuevo' => EstadoOrden::EnProceso,
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
