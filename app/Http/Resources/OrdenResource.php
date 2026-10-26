<?php

namespace App\Http\Resources;

use App\Enums\EstadoOrden;
use App\Models\OrdenServicio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'empresa_id' => $this->empresa_id,
            'numero' => $this->numero,
            'proforma_id' => $this->proforma_id,
            'cliente_id' => $this->cliente_id,
            'operario_id' => $this->operario_id,
            'estado' => $this->estado?->value,
            'fecha_programada' => $this->fecha_programada?->toDateString(),
            'fecha_inicio' => $this->fecha_inicio?->toISOString(),
            'fecha_fin' => $this->fecha_fin?->toISOString(),
            'observaciones' => $this->observaciones,
            'motivo_cancelacion' => $this->motivo_cancelacion,
            'cancelada_at' => $this->cancelada_at?->toISOString(),
            'cerrada_at' => $this->cerrada_at?->toISOString(),
            'cerrada_por' => $this->cerrada_por,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'items_count' => $this->whenCounted('items'),
            'cuenta_por_cobrar_id' => $this->whenLoaded(
                'cuentaPorCobrar',
                fn () => $this->cuentaPorCobrar?->id,
                null,
            ),
            'cuenta' => $this->whenLoaded(
                'cuentaPorCobrar',
                fn () => $this->cuentaPorCobrar === null ? null : [
                    'id' => $this->cuentaPorCobrar->id,
                    'numero' => $this->cuentaPorCobrar->numero,
                    'saldo' => (float) $this->cuentaPorCobrar->saldo,
                    'estado_cobro' => $this->cuentaPorCobrar->estado_cobro?->value,
                ],
                null,
            ),
            'acciones_disponibles' => $this->accionesDisponibles($request),
            'cliente' => $this->whenLoaded('cliente', fn () => new ClienteResource($this->cliente)),
            'proforma' => $this->whenLoaded('proforma', fn () => [
                'id' => $this->proforma->id,
                'numero' => $this->proforma->numero,
                'estado' => $this->proforma->estado?->value,
                'moneda' => $this->proforma->moneda,
                'total' => (float) $this->proforma->total,
            ]),
            'operario' => $this->whenLoaded('operario', fn () => new UserResource($this->operario)),
            'items' => $this->whenLoaded(
                'items',
                fn () => OrdenItemResource::collection($this->items)
            ),
            'eventos' => $this->whenLoaded(
                'eventos',
                fn () => OrdenEventoResource::collection($this->eventos)
            ),
            'cerrador' => $this->whenLoaded('cerrador', fn () => new UserResource($this->cerrador)),
            'creador' => $this->whenLoaded('creador', fn () => new UserResource($this->creador)),
            'editor' => $this->whenLoaded('editor', fn () => new UserResource($this->editor)),
            'creado_en' => $this->created_at?->toISOString(),
            'actualizado_en' => $this->updated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Acciones que la UI puede ofrecer sin contradecir las policies.
     *
     * @return list<string>
     */
    private function accionesDisponibles(Request $request): array
    {
        $user = $request->user();
        $orden = $this->resource;

        if ($user === null || ! $orden instanceof OrdenServicio) {
            return [];
        }

        $acciones = [];

        if ($user->can('view', $orden)) {
            $acciones[] = 'ver';
        }

        if ($orden->estado === EstadoOrden::Pendiente && $user->can('asignar', $orden)) {
            $acciones[] = 'asignar';
        }

        if ($orden->estado === EstadoOrden::Asignada && $user->can('iniciar', $orden)) {
            $acciones[] = 'iniciar';
        }

        if ($orden->estado === EstadoOrden::EnProceso && $user->can('finalizar', $orden)) {
            $acciones[] = 'finalizar';
        }

        if ($orden->estado?->esActiva() && $user->can('cancelar', $orden)) {
            $acciones[] = 'cancelar';
        }

        if (
            $orden->estado === EstadoOrden::Finalizada
            && ! $orden->estaCerrada()
            && $user->can('cerrar', $orden)
        ) {
            $acciones[] = 'cerrar';
        }

        if (
            in_array($orden->estado, [EstadoOrden::EnProceso, EstadoOrden::Finalizada], true)
            && ! $orden->estaCerrada()
            && $orden->tieneMaterialesPendientes()
            && $user->can('consumirMateriales', $orden)
        ) {
            $acciones[] = 'consumir';
        }

        return $acciones;
    }
}
