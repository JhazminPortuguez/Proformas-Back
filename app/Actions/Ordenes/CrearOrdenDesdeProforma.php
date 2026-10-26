<?php

declare(strict_types=1);

namespace App\Actions\Ordenes;

use App\Enums\EstadoOrden;
use App\Enums\EstadoProforma;
use App\Enums\TipoOrdenEvento;
use App\Enums\TipoProformaItem;
use App\Models\OrdenServicio;
use App\Models\Proforma;
use App\Models\User;
use App\Support\OperarioAsignable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrearOrdenDesdeProforma
{
    public function __construct(
        private readonly GenerarNumeroOrden $numeros,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function ejecutar(array $datos, User $usuario): OrdenServicio
    {
        return DB::transaction(function () use ($datos, $usuario): OrdenServicio {
            $proforma = Proforma::query()
                ->whereKey($datos['proforma_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($proforma->estado !== EstadoProforma::Aceptada) {
                throw ValidationException::withMessages([
                    'proforma_id' => 'Solo se puede crear una orden de servicio desde una proforma aceptada.',
                ]);
            }

            // El bloqueo de la proforma serializa las creaciones concurrentes.
            $existeVigente = OrdenServicio::query()
                ->where('proforma_id', $proforma->getKey())
                ->where('estado', '!=', EstadoOrden::Cancelada->value)
                ->exists();

            if ($existeVigente) {
                throw ValidationException::withMessages([
                    'proforma_id' => 'La proforma ya tiene una orden de servicio vigente.',
                ]);
            }

            $operarioId = isset($datos['operario_id']) ? (int) $datos['operario_id'] : null;
            $operario = $operarioId !== null
                ? OperarioAsignable::validar($operarioId)
                : null;

            $orden = OrdenServicio::create([
                'proforma_id' => $proforma->getKey(),
                'cliente_id' => $proforma->cliente_id,
                'numero' => $this->numeros->ejecutar(now()),
                'operario_id' => null,
                'estado' => EstadoOrden::Pendiente,
                'fecha_programada' => $datos['fecha_programada'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'created_by' => $usuario->id,
                'updated_by' => $usuario->id,
            ]);

            $orden->items()->createMany($this->lineasSnapshot($proforma));

            $orden->eventos()->create([
                'tipo' => TipoOrdenEvento::Creacion,
                'descripcion' => 'Orden creada desde la proforma '.$proforma->numero.'.',
                'estado_anterior' => null,
                'estado_nuevo' => EstadoOrden::Pendiente,
                'user_id' => $usuario->id,
                'metadata' => [
                    'proforma_id' => $proforma->getKey(),
                    'proforma_numero' => $proforma->numero,
                ],
            ]);

            if ($operario !== null) {
                $orden->update([
                    'operario_id' => $operario->id,
                    'estado' => EstadoOrden::Asignada,
                    'updated_by' => $usuario->id,
                ]);

                $orden->eventos()->create([
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
            }

            return $this->cargar($orden);
        });
    }

    /**
     * Copia inmutable de las líneas de la proforma al momento de crear la orden.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineasSnapshot(Proforma $proforma): array
    {
        $proforma->loadMissing('items');

        return $proforma->items->map(function ($item): array {
            $descripcion = trim((string) ($item->descripcion ?: $item->nombre));

            if ($descripcion === '') {
                $descripcion = 'Ítem sin descripción';
            }

            return [
                'tipo' => $item->tipo?->value ?? TipoProformaItem::Material->value,
                'servicio_id' => $item->servicio_id,
                'producto_id' => $item->producto_id,
                'descripcion' => $descripcion,
                'unidad_medida' => $item->unidad_medida,
                'cantidad' => $item->cantidad,
                'precio_unitario' => $item->precio_unitario,
                'total_linea' => $item->total_linea,
            ];
        })->all();
    }

    private function cargar(OrdenServicio $orden): OrdenServicio
    {
        return $orden->load('cliente', 'proforma', 'operario', 'items', 'eventos.usuario')
            ->loadCount('items');
    }
}
