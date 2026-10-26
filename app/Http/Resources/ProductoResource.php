<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'precio_unitario' => (float) $this->precio_unitario,
            'unidad_medida' => $this->unidad_medida,
            'stock_actual' => (float) $this->stock_actual,
            'stock_minimo' => (float) $this->stock_minimo,
            'costo_promedio' => (float) $this->costo_promedio,
            'stock_bajo' => $this->stockBajo(),
            'valor_stock' => (float) $this->valorStock(),
            'movimientos_count' => $this->whenCounted('movimientos'),
            'activo' => $this->activo,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
