<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Http\Resources\SolicitudItemResource;
use App\Models\SolicitudCotizacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Solicitud del cliente en el portal.
 *
 * `proforma` solo aparece cuando la proforma asociada ya fue enviada: una
 * proforma en borrador no se muestra ni se enlaza.
 *
 * @mixin SolicitudCotizacion
 */
class PortalSolicitudResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $proforma = $this->relationLoaded('proforma') ? $this->proforma : null;
        $visible = $proforma !== null && ($proforma->estado?->esVisibleParaCliente() ?? false);

        return [
            'id' => $this->id,
            'descripcion' => $this->descripcion,
            'estado' => $this->estado?->value,
            'fecha' => $this->created_at?->toISOString(),
            'items_count' => $this->items_count ?? ($this->relationLoaded('items') ? $this->items->count() : null),
            'items' => $this->whenLoaded('items', fn () => SolicitudItemResource::collection($this->items)),
            'proforma' => $visible ? [
                'id' => $proforma->id,
                'numero' => $proforma->numero,
                'estado' => $proforma->estado->value,
            ] : null,
        ];
    }
}
