<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Enums\EstadoProforma;
use App\Models\Proforma;
use App\Support\PeriodoReporte;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Proforma tal como la ve el cliente en el portal.
 *
 * No incluye datos internos (quién la creó o aceptó, origen, costos) ni el
 * motivo de rechazo. Las observaciones sí, porque también salen en el PDF.
 *
 * @mixin Proforma
 */
class PortalProformaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $primerItem = $this->relationLoaded('primerItem') ? $this->primerItem : null;
        $hoy = PeriodoReporte::hoy()->toDateString();
        $validez = $this->fecha_validez?->toDateString();

        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'estado' => $this->estado?->value,
            'fecha_emision' => $this->fecha_emision?->toDateString(),
            'fecha_validez' => $validez,
            // Solo una proforma enviada puede vencer: la aceptada o la
            // rechazada ya tienen respuesta. No es un estado.
            'vencida' => $this->estado === EstadoProforma::Enviada && $validez !== null && $validez < $hoy,
            'moneda' => $this->moneda,
            'total' => (float) $this->total,
            'items_count' => $this->items_count ?? ($this->relationLoaded('items') ? $this->items->count() : null),
            'primer_item' => $primerItem?->nombre ?? $primerItem?->descripcion,
            'solicitud_id' => $this->solicitud_id,
            $this->mergeWhen($this->relationLoaded('items'), fn () => [
                'subtotal' => (float) $this->subtotal,
                'descuento' => (float) $this->descuento,
                'impuesto_tasa' => (float) $this->impuesto_tasa,
                'impuesto' => (float) $this->impuesto,
                'observaciones' => $this->observaciones,
                'items' => $this->items->map(fn ($item) => [
                    'id' => $item->id,
                    'nombre' => $item->nombre,
                    'descripcion' => $item->descripcion,
                    'unidad_medida' => $item->unidad_medida,
                    'cantidad' => (float) $item->cantidad,
                    'precio_unitario' => (float) $item->precio_unitario,
                    'total_linea' => (float) $item->total_linea,
                ])->values()->all(),
            ]),
        ];
    }
}
