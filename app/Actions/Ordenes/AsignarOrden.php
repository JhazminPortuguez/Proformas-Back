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

            // O-04: una orden Asignada (todavía no iniciada) se puede reasignar;
            // una En proceso ya no.
            if (! in_array($actual->estado, [EstadoOrden::Pendiente, EstadoOrden::Asignada], true)) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede asignar una orden pendiente o reasignar una asignada que no se inició.',
                ]);
            }

            $operario = OperarioAsignable::validar($operarioId);
            $reasigna = $actual->estado === EstadoOrden::Asignada;
            $anterior = $reasigna ? $actual->operario()->first(['id', 'name']) : null;

            if ($reasigna && (int) $actual->operario_id === $operario->id) {
                throw ValidationException::withMessages([
                    'operario_id' => 'La orden ya está asignada a '.$operario->name.'. Elige otro operario.',
                ]);
            }

            $actual->update([
                'operario_id' => $operario->id,
                'estado' => EstadoOrden::Asignada,
                'updated_by' => $usuario->id,
            ]);

            $actual->eventos()->create([
                'tipo' => TipoOrdenEvento::Asignacion,
                'descripcion' => $reasigna
                    ? 'Orden reasignada de '.($anterior?->name ?? 'sin operario').' a '.$operario->name.'.'
                    : 'Orden asignada a '.$operario->name.'.',
                'estado_anterior' => $reasigna ? EstadoOrden::Asignada : EstadoOrden::Pendiente,
                'estado_nuevo' => EstadoOrden::Asignada,
                'user_id' => $usuario->id,
                'metadata' => [
                    'operario_id' => $operario->id,
                    'operario' => $operario->name,
                    ...($reasigna ? [
                        'reasignacion' => true,
                        'operario_anterior_id' => $anterior?->id,
                        'operario_anterior' => $anterior?->name,
                    ] : []),
                ],
            ]);

            return $actual->load('cliente', 'proforma', 'operario', 'items', 'eventos.usuario')
                ->loadCount('items');
        });
    }
}
