<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\EstadoProforma;
use App\Models\Proforma;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RechazarProforma
{
    public function ejecutar(Proforma $proforma, string $motivo, User $usuario): Proforma
    {
        return DB::transaction(function () use ($proforma, $motivo, $usuario) {
            $actual = Proforma::query()
                ->whereKey($proforma->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado !== EstadoProforma::Enviada) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede rechazar una proforma enviada.',
                ]);
            }

            $actual->update([
                'estado' => EstadoProforma::Rechazada,
                'rechazada_at' => now(),
                'motivo_rechazo' => trim($motivo),
                'updated_by' => $usuario->id,
            ]);

            return $actual->load('cliente', 'items.producto', 'items.servicio');
        });
    }
}
