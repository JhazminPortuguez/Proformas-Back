<?php

namespace App\Http\Resources;

use App\Models\PagoCuenta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PagoCuentaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'empresa_id' => $this->empresa_id,
            'cuenta_id' => $this->cuenta_id,
            'monto' => (float) $this->monto,
            'metodo' => $this->metodo?->value,
            'metodo_etiqueta' => $this->metodo?->etiqueta(),
            'fecha_pago' => $this->fecha_pago?->toDateString(),
            'referencia' => $this->referencia,
            'observaciones' => $this->observaciones,
            'created_by' => $this->created_by,
            'anulado' => $this->estaAnulado(),
            'anulado_at' => $this->anulado_at?->toISOString(),
            'anulado_por' => $this->anulado_por,
            'motivo_anulacion' => $this->motivo_anulacion,
            'puede_anular' => $this->puedeAnular($request),
            'acciones_disponibles' => $this->puedeAnular($request) ? ['anular_pago'] : [],
            'cuenta' => $this->whenLoaded('cuenta', fn () => $this->cuenta === null ? null : [
                'id' => $this->cuenta->id,
                'numero' => $this->cuenta->numero,
            ]),
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario === null ? null : new UserResource($this->usuario)),
            'anulador' => $this->whenLoaded('anulador', fn () => $this->anulador === null ? null : new UserResource($this->anulador)),
            'creado_en' => $this->created_at?->toISOString(),
            'actualizado_en' => $this->updated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function puedeAnular(Request $request): bool
    {
        $user = $request->user();
        $pago = $this->resource;

        return $user !== null
            && $pago instanceof PagoCuenta
            && ! $pago->estaAnulado()
            && $user->can('anular', $pago);
    }
}
