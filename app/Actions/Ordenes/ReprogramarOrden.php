<?php

declare(strict_types=1);

namespace App\Actions\Ordenes;

use App\Enums\TipoOrdenEvento;
use App\Models\OrdenServicio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReprogramarOrden
{
    public function ejecutar(OrdenServicio $orden, string $fechaNueva, User $usuario): OrdenServicio
    {
        return DB::transaction(function () use ($orden, $fechaNueva, $usuario): OrdenServicio {
            $actual = OrdenServicio::query()
                ->whereKey($orden->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $actual->estado?->admiteReprogramacion()) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede reprogramar una orden pendiente o asignada.',
                ]);
            }

            $fechaAnterior = $actual->fecha_programada?->toDateString();

            if ($fechaAnterior === $fechaNueva) {
                throw ValidationException::withMessages([
                    'fecha_programada' => 'La fecha nueva es igual a la fecha programada actual.',
                ]);
            }

            $actual->update([
                'fecha_programada' => $fechaNueva,
                'updated_by' => $usuario->id,
            ]);

            // El estado no cambia: el evento solo deja la fecha anterior y la nueva.
            $actual->eventos()->create([
                'tipo' => TipoOrdenEvento::Reprogramacion,
                'descripcion' => sprintf(
                    'Fecha programada cambiada de %s a %s.',
                    $this->formato($fechaAnterior),
                    $this->formato($fechaNueva),
                ),
                'estado_anterior' => null,
                'estado_nuevo' => null,
                'user_id' => $usuario->id,
                'metadata' => [
                    'fecha_anterior' => $fechaAnterior,
                    'fecha_nueva' => $fechaNueva,
                ],
            ]);

            return $actual->load('cliente', 'proforma', 'operario', 'items', 'eventos.usuario')
                ->loadCount('items');
        });
    }

    private function formato(?string $fecha): string
    {
        return $fecha === null
            ? 'sin fecha'
            : CarbonImmutable::parse($fecha)->format('d/m/Y');
    }
}
