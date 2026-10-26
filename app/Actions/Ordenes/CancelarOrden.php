<?php

declare(strict_types=1);

namespace App\Actions\Ordenes;

use App\Enums\EstadoOrden;
use App\Enums\TipoOrdenEvento;
use App\Models\OrdenServicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelarOrden
{
    public function ejecutar(OrdenServicio $orden, string $motivo, User $usuario): OrdenServicio
    {
        return DB::transaction(function () use ($orden, $motivo, $usuario): OrdenServicio {
            $actual = OrdenServicio::query()
                ->whereKey($orden->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $actual->estado?->esActiva()) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede cancelar una orden pendiente, asignada o en proceso.',
                ]);
            }

            $motivo = trim($motivo);

            if ($motivo === '') {
                throw ValidationException::withMessages([
                    'motivo' => 'El motivo de cancelación es obligatorio.',
                ]);
            }

            $estadoAnterior = $actual->estado;

            $actual->update([
                'estado' => EstadoOrden::Cancelada,
                'motivo_cancelacion' => $motivo,
                'cancelada_at' => now(),
                'updated_by' => $usuario->id,
            ]);

            $actual->eventos()->create([
                'tipo' => TipoOrdenEvento::Cancelacion,
                'descripcion' => 'Orden cancelada.',
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => EstadoOrden::Cancelada,
                'user_id' => $usuario->id,
                'metadata' => ['motivo' => $motivo],
            ]);

            return $actual->load('cliente', 'proforma', 'operario', 'items', 'eventos.usuario')
                ->loadCount('items');
        });
    }
}
