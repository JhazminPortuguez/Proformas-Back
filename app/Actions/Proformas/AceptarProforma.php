<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\EstadoProforma;
use App\Models\Proforma;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AceptarProforma
{
    public function ejecutar(Proforma $proforma, User $usuario): Proforma
    {
        return DB::transaction(function () use ($proforma, $usuario) {
            $actual = Proforma::query()
                ->whereKey($proforma->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado !== EstadoProforma::Enviada) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede aceptar una proforma enviada.',
                ]);
            }

            $actual->update([
                'estado' => EstadoProforma::Aceptada,
                'aceptada_at' => now(),
                'aceptada_por' => $usuario->id,
                'updated_by' => $usuario->id,
            ]);

            return $actual->load('cliente', 'items.producto', 'items.servicio', 'aceptador');
        });
    }
}
