<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdenItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orden_id' => $this->orden_id,
            'tipo' => $this->tipo?->value,
            'servicio_id' => $this->servicio_id,
            'producto_id' => $this->producto_id,
            'descripcion' => $this->descripcion,
            'unidad_medida' => $this->unidad_medida,
            'cantidad' => (float) $this->cantidad,
            'cantidad_consumida' => (float) $this->cantidad_consumida,
            'cantidad_pendiente' => (float) $this->cantidadPendiente(),
            'consumido' => $this->estaConsumido(),
            'consumido_at' => $this->consumido_at?->toISOString(),
            'precio_unitario' => (float) $this->precio_unitario,
            'total_linea' => (float) $this->total_linea,
            'producto' => $this->whenLoaded('producto', fn () => new ProductoResource($this->producto)),
            'servicio' => $this->whenLoaded('servicio', fn () => new ServicioResource($this->servicio)),
        ];
    }
}
