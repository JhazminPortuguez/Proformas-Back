<?php

namespace App\Http\Resources;

use App\Support\DescuentoPromocion;
use App\Support\FechaBolivia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromocionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hoy = FechaBolivia::hoyTexto();

        $estado = match (true) {
            ! $this->activa => 'INACTIVA',
            $this->vigente_hasta->toDateString() < $hoy => 'VENCIDA',
            $this->vigente_desde->toDateString() > $hoy => 'PROGRAMADA',
            default => 'VIGENTE',
        };

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo->value,
            'tipo_etiqueta' => $this->tipo->etiqueta(),
            'valor' => $this->valor === null ? null : (float) $this->valor,
            'etiqueta' => DescuentoPromocion::etiqueta($this->tipo, $this->valor),
            'aplica_a' => $this->aplica_a->value,
            'aplica_a_etiqueta' => $this->aplica_a->etiqueta(),
            'servicio_id' => $this->servicio_id,
            'producto_id' => $this->producto_id,
            'servicio' => $this->whenLoaded('servicio', fn () => $this->servicio ? ['id' => $this->servicio->id, 'nombre' => $this->servicio->nombre] : null),
            'producto' => $this->whenLoaded('producto', fn () => $this->producto ? ['id' => $this->producto->id, 'nombre' => $this->producto->nombre] : null),
            'vigente_desde' => $this->vigente_desde->toDateString(),
            'vigente_hasta' => $this->vigente_hasta->toDateString(),
            'activa' => $this->activa,
            'estado' => $estado,
            'vigente' => $estado === 'VIGENTE',
            'usos' => $this->when(isset($this->lineas_proforma_count), fn () => (int) $this->lineas_proforma_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
