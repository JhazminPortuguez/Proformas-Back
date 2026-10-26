<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoInventarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'empresa_id' => $this->empresa_id,
            'producto_id' => $this->producto_id,
            'orden_id' => $this->orden_id,
            'orden_item_id' => $this->orden_item_id,
            'tipo' => $this->tipo?->value,
            'cantidad' => (float) $this->cantidad,
            'costo_unitario' => (float) $this->costo_unitario,
            'stock_anterior' => (float) $this->stock_anterior,
            'stock_nuevo' => (float) $this->stock_nuevo,
            'motivo' => $this->motivo,
            'created_by' => $this->created_by,
            'producto' => $this->whenLoaded('producto', fn () => new ProductoResource($this->producto)),
            'orden' => $this->whenLoaded('orden', fn () => $this->orden === null ? null : [
                'id' => $this->orden->id,
                'numero' => $this->orden->numero,
            ]),
            'orden_item' => $this->whenLoaded('ordenItem', fn () => $this->ordenItem === null ? null : new OrdenItemResource($this->ordenItem)),
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario === null ? null : new UserResource($this->usuario)),
            'creado_en' => $this->created_at?->toISOString(),
            'actualizado_en' => $this->updated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
