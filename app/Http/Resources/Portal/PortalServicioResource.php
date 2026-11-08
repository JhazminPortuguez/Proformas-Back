<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Models\Servicio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Servicio del catálogo tal como lo ve el cliente (sin datos internos).
 *
 * @mixin Servicio
 */
class PortalServicioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'categoria' => $this->categoria,
            'precio_base' => (float) $this->precio_base,
            'duracion_estimada_min' => $this->duracion_estimada_min,
        ];
    }
}
