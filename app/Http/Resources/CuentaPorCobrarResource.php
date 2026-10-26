<?php

namespace App\Http\Resources;

use App\Models\CuentaPorCobrar;
use App\Support\Dinero;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CuentaPorCobrarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'empresa_id' => $this->empresa_id,
            'orden_id' => $this->orden_id,
            'cliente_id' => $this->cliente_id,
            'numero' => $this->numero,
            'fecha_emision' => $this->fecha_emision?->toDateString(),
            'fecha_vencimiento' => $this->fecha_vencimiento?->toDateString(),
            'moneda' => $this->moneda,
            'monto_total' => (float) $this->monto_total,
            'saldo' => (float) $this->saldo,
            'total_pagado' => (float) $this->totalPagado(),
            'estado_cobro' => $this->estado_cobro?->value,
            'estado_cobro_etiqueta' => $this->estado_cobro?->etiqueta(),
            'vencida' => $this->estaVencida(),
            'dias_vencida' => $this->diasVencida(),
            'observaciones' => $this->observaciones,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'pagos_count' => $this->whenCounted('pagos'),
            'acciones_disponibles' => $this->accionesDisponibles($request),
            'cliente' => $this->whenLoaded('cliente', fn () => new ClienteResource($this->cliente)),
            'orden' => $this->whenLoaded('orden', fn () => $this->orden === null ? null : [
                'id' => $this->orden->id,
                'numero' => $this->orden->numero,
                'estado' => $this->orden->estado?->value,
            ]),
            'pagos' => $this->whenLoaded(
                'pagos',
                fn () => PagoCuentaResource::collection($this->pagos)
            ),
            'creador' => $this->whenLoaded('creador', fn () => new UserResource($this->creador)),
            'editor' => $this->whenLoaded('editor', fn () => new UserResource($this->editor)),
            'creado_en' => $this->created_at?->toISOString(),
            'actualizado_en' => $this->updated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return list<string>
     */
    private function accionesDisponibles(Request $request): array
    {
        $user = $request->user();
        $cuenta = $this->resource;

        if ($user === null || ! $cuenta instanceof CuentaPorCobrar) {
            return [];
        }

        $acciones = [];

        if ($user->can('view', $cuenta)) {
            $acciones[] = 'ver';
        }

        if (
            Dinero::mayorQue($cuenta->saldo, 0)
            && $user->can('registrarPago', $cuenta)
        ) {
            $acciones[] = 'pagar';
        }

        return $acciones;
    }
}
