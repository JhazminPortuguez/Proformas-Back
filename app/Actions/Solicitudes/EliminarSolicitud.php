<?php

declare(strict_types=1);

namespace App\Actions\Solicitudes;

use App\Models\SolicitudCotizacion;
use Illuminate\Support\Facades\DB;

final class EliminarSolicitud
{
    public function ejecutar(SolicitudCotizacion $solicitud): void
    {
        DB::transaction(function () use ($solicitud): void {
            $actual = SolicitudCotizacion::query()
                ->whereKey($solicitud->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->convertida_proforma_id !== null) {
                abort(409, 'No se puede eliminar una solicitud ya convertida.');
            }

            if (! $actual->estado->permiteEditar()) {
                abort(409, 'Solo se puede eliminar una solicitud pendiente.');
            }

            $actual->items()->delete();
            $actual->delete();
        });
    }
}
