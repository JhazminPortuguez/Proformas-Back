<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\CanalRespuesta;
use App\Enums\EstadoProforma;
use App\Models\Proforma;
use App\Models\User;
use App\Support\RespuestaPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RechazarProforma
{
    /**
     * El motivo es obligatorio para el personal (lo valida su request) y
     * opcional para el cliente en el portal.
     */
    public function ejecutar(
        Proforma $proforma,
        ?string $motivo,
        User $usuario,
        CanalRespuesta $canal = CanalRespuesta::Interno,
    ): Proforma {
        return DB::transaction(function () use ($proforma, $motivo, $usuario, $canal) {
            $actual = Proforma::query()
                ->whereKey($proforma->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($canal === CanalRespuesta::Portal) {
                RespuestaPortal::verificar($actual, $usuario, 'rechazar');
            }

            if ($actual->estado !== EstadoProforma::Enviada) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede rechazar una proforma enviada.',
                ]);
            }

            $actual->update([
                'estado' => EstadoProforma::Rechazada,
                'rechazada_at' => now(),
                'rechazada_por' => $usuario->id,
                'rechazada_canal' => $canal->value,
                'motivo_rechazo' => trim((string) $motivo) === '' ? null : trim((string) $motivo),
                'updated_by' => $usuario->id,
            ]);

            return $actual->load('cliente', 'items.producto', 'items.servicio', 'rechazador');
        });
    }
}
