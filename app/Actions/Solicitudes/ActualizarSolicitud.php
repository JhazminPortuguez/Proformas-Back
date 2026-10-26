<?php

declare(strict_types=1);

namespace App\Actions\Solicitudes;

use App\Models\SolicitudCotizacion;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActualizarSolicitud
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function ejecutar(SolicitudCotizacion $solicitud, array $datos, User $usuario): SolicitudCotizacion
    {
        return DB::transaction(function () use ($solicitud, $datos, $usuario) {
            $actual = SolicitudCotizacion::query()
                ->whereKey($solicitud->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $actual->estado->permiteEditar()) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede editar una solicitud pendiente.',
                ]);
            }

            $cambios = ['updated_by' => $usuario->id];

            if (array_key_exists('descripcion', $datos)) {
                $cambios['descripcion'] = $datos['descripcion'];
            }

            $actual->update($cambios);

            if (array_key_exists('items', $datos)) {
                $actual->items()->delete();
                $actual->items()->createMany($this->lineas($datos['items']));
            }

            return $actual->load('cliente', 'items');
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function lineas(array $items): array
    {
        $lineas = [];

        foreach ($items as $indice => $item) {
            $cantidad = Dinero::cantidad($item['cantidad'] ?? 1);

            if (Dinero::comparar($cantidad, 0) <= 0) {
                throw ValidationException::withMessages([
                    "items.$indice.cantidad" => 'La cantidad debe ser mayor que cero.',
                ]);
            }

            $lineas[] = [
                'descripcion' => trim((string) $item['descripcion']),
                'cantidad' => $cantidad,
                'unidad_medida' => $item['unidad_medida'] ?? null,
                'observaciones' => $item['observaciones'] ?? null,
            ];
        }

        return $lineas;
    }
}
