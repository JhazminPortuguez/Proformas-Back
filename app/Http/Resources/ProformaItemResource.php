<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProformaItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo?->value,
            'producto_id' => $this->producto_id,
            'servicio_id' => $this->servicio_id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'unidad_medida' => $this->unidad_medida,
            'cantidad' => (float) $this->cantidad,
            'precio_unitario' => (float) $this->precio_unitario,
            'total_linea' => (float) $this->total_linea,
            'producto' => $this->whenLoaded('producto', fn () => new ProductoResource($this->producto)),
            'servicio' => $this->whenLoaded('servicio', fn () => new ServicioResource($this->servicio)),
        ];
    }
}
