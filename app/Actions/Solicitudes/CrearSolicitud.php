<?php

declare(strict_types=1);

namespace App\Actions\Solicitudes;

use App\Enums\EstadoSolicitud;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrearSolicitud
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function ejecutar(array $datos, User $usuario): SolicitudCotizacion
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $solicitud = SolicitudCotizacion::create([
                'cliente_id' => $datos['cliente_id'],
                'descripcion' => $datos['descripcion'],
                'estado' => EstadoSolicitud::Pendiente,
                'created_by' => $usuario->id,
                'updated_by' => $usuario->id,
            ]);

            $solicitud->items()->createMany($this->lineas($datos['items'] ?? []));

            return $solicitud->load('cliente', 'items');
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
