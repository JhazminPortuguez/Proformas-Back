<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClienteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre_razon' => $this->nombre_razon,
            'nit_ci' => $this->nit_ci,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'direccion' => $this->direccion,
            'estado' => $this->estado?->value,
            'empresa_id' => $this->empresa_id,
            'proformas_count' => $this->whenCounted('proformas'),
            'proformas' => $this->whenLoaded(
                'proformas',
                fn () => ProformaResource::collection($this->proformas)
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
