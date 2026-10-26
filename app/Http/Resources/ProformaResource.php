<?php

namespace App\Http\Resources;

use App\Enums\EstadoProforma;
use App\Models\OrdenServicio;
use App\Models\Proforma;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProformaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'cliente_id' => $this->cliente_id,
            'fecha_emision' => $this->fecha_emision?->toDateString(),
            'fecha_validez' => $this->fecha_validez?->toDateString(),
            'estado' => $this->estado?->value,
            'moneda' => $this->moneda,
            'impuesto_tasa' => (float) $this->impuesto_tasa,
            'subtotal' => (float) $this->subtotal,
            'descuento' => (float) $this->descuento,
            'impuesto' => (float) $this->impuesto,
            'total' => (float) $this->total,
            'observaciones' => $this->observaciones,
            'origen' => $this->origen?->value,
            'solicitud_id' => $this->solicitud_id,
            'orden_id' => $this->orden?->id,
            'items_count' => $this->whenCounted('items'),
            'acciones_disponibles' => $this->accionesDisponibles($request),
            'enviada_at' => $this->enviada_at?->toISOString(),
            'aceptada_at' => $this->aceptada_at?->toISOString(),
            'aceptada_por' => $this->aceptada_por,
            'rechazada_at' => $this->rechazada_at?->toISOString(),
            'motivo_rechazo' => $this->motivo_rechazo,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'cliente' => $this->whenLoaded('cliente', fn () => new ClienteResource($this->cliente)),
            'items' => $this->whenLoaded(
                'items',
                fn () => ProformaItemResource::collection($this->items)
            ),
            'aceptador' => $this->whenLoaded('aceptador', fn () => new UserResource($this->aceptador)),
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
        $proforma = $this->resource;

        if ($user === null || ! $proforma instanceof Proforma) {
            return [];
        }

        $acciones = ['ver'];

        if ($proforma->estado === EstadoProforma::Borrador) {
            if ($user->can('update', $proforma)) {
                $acciones[] = 'editar';
            }

            if ($user->can('enviar', $proforma)) {
                $acciones[] = 'enviar';
            }

            if ($user->can('delete', $proforma)) {
                $acciones[] = 'eliminar';
            }
        }

        if ($proforma->estado === EstadoProforma::Enviada) {
            if ($user->can('aceptar', $proforma)) {
                $acciones[] = 'aceptar';
            }

            if ($user->can('rechazar', $proforma)) {
                $acciones[] = 'rechazar';
            }
        }

        if (
            $proforma->estado === EstadoProforma::Aceptada
            && $user->can('create', OrdenServicio::class)
            && ! $proforma->tieneOrdenVigente()
        ) {
            $acciones[] = 'crear_orden';
        }

        if ($user->can('descargarPdf', $proforma)) {
            $acciones[] = 'pdf';
        }

        return $acciones;
    }
}
