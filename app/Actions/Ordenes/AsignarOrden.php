<?php

declare(strict_types=1);

namespace App\Actions\Ordenes;

use App\Enums\EstadoOrden;
use App\Enums\TipoOrdenEvento;
use App\Models\OrdenServicio;
use App\Models\User;
use App\Support\OperarioAsignable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AsignarOrden
{
    public function ejecutar(OrdenServicio $orden, int $operarioId, User $usuario): OrdenServicio
    {
        return DB::transaction(function () use ($orden, $operarioId, $usuario): OrdenServicio {
            $actual = OrdenServicio::query()
                ->whereKey($orden->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado !== EstadoOrden::Pendiente) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede asignar una orden pendiente.',
                ]);
            }

            $operario = OperarioAsignable::validar($operarioId);

            $actual->update([
                'operario_id' => $operario->id,
                'estado' => EstadoOrden::Asignada,
                'updated_by' => $usuario->id,
            ]);

            $actual->eventos()->create([
                'tipo' => TipoOrdenEvento::Asignacion,
                'descripcion' => 'Orden asignada a '.$operario->name.'.',
                'estado_anterior' => EstadoOrden::Pendiente,
                'estado_nuevo' => EstadoOrden::Asignada,
                'user_id' => $usuario->id,
                'metadata' => [
                    'operario_id' => $operario->id,
                    'operario' => $operario->name,
                ],
            ]);

            return $actual->load('cliente', 'proforma', 'operario', 'items', 'eventos.usuario')
                ->loadCount('items');
        });
    }
}
