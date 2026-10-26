<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdenEventoResource extends JsonResource
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
            'descripcion' => $this->descripcion,
            'estado_anterior' => $this->estado_anterior?->value,
            'estado_nuevo' => $this->estado_nuevo?->value,
            'user_id' => $this->user_id,
            'metadata' => $this->metadata,
            'usuario' => $this->whenLoaded('usuario', fn () => new UserResource($this->usuario)),
            'creado_en' => $this->created_at?->toISOString(),
            'actualizado_en' => $this->updated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
