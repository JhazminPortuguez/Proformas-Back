<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitudResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cliente_id' => $this->cliente_id,
            'descripcion' => $this->descripcion,
            'estado' => $this->estado?->value,
            'convertida_proforma_id' => $this->convertida_proforma_id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'cliente' => $this->whenLoaded('cliente', fn () => new ClienteResource($this->cliente)),
            'items' => $this->whenLoaded('items', fn () => SolicitudItemResource::collection($this->items)),
            'proforma' => $this->whenLoaded('proforma', fn () => new ProformaResource($this->proforma)),
            'creado_en' => $this->created_at?->toISOString(),
            'actualizado_en' => $this->updated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
