<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HistorialServicioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $orden = $this->orden;

        return [
            'id' => $this->id,
            'orden_id' => $this->orden_id,
            'orden_numero' => $orden?->numero,
            'orden_estado' => $orden?->estado?->value,
            'fecha' => $orden?->fecha_fin?->toDateString() ?? $orden?->created_at?->toDateString(),
            'servicio_id' => $this->servicio_id,
            'servicio_nombre' => $this->servicio?->nombre,
            'descripcion' => $this->descripcion,
            'cantidad' => (float) $this->cantidad,
            'total_linea' => (float) $this->total_linea,
            'operario_nombre' => $orden?->operario?->name,
        ];
    }
}
