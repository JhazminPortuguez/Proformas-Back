<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'rol' => $this->rol?->value,
            'activo' => (bool) $this->activo,
            'cliente_id' => $this->cliente_id,
            'empresa_id' => $this->empresa_id,
            // Ronda B, parte 2: cuenta creada con Google a la que le faltan
            // el teléfono o el NIT/CI para enviar solicitudes.
            'datos_pendientes' => (bool) $this->datos_pendientes,
            'cliente' => $this->whenLoaded('cliente', fn () => new ClienteResource($this->cliente)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
