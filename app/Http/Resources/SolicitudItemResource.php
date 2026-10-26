<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitudItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'descripcion' => $this->descripcion,
            'cantidad' => (float) $this->cantidad,
            'unidad_medida' => $this->unidad_medida,
            'observaciones' => $this->observaciones,
        ];
    }
}
