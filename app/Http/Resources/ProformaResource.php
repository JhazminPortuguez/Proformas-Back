<?php

namespace App\Http\Resources;

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
            'subtotal' => (float) $this->subtotal,
            'descuento' => (float) $this->descuento,
            'impuesto' => (float) $this->impuesto,
            'total' => (float) $this->total,
            'observaciones' => $this->observaciones,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'cliente' => $this->whenLoaded('cliente', fn () => new ClienteResource($this->cliente)),
            'items' => $this->whenLoaded(
                'items',
                fn () => ProformaItemResource::collection($this->items)
            ),
            'creado_en' => $this->created_at?->toISOString(),
            'actualizado_en' => $this->updated_at?->toISOString(),
        ];
    }
}
