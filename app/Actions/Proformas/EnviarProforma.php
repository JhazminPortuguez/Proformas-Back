<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\EstadoProforma;
use App\Models\Proforma;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EnviarProforma
{
    public function ejecutar(Proforma $proforma, User $usuario): Proforma
    {
        return DB::transaction(function () use ($proforma, $usuario) {
            $actual = Proforma::query()
                ->whereKey($proforma->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado !== EstadoProforma::Borrador) {
                throw ValidationException::withMessages([
                    'estado' => "No se puede enviar una proforma en estado {$actual->estado->etiqueta()}.",
                ]);
            }

            $actual->update([
                'estado' => EstadoProforma::Enviada,
                'enviada_at' => now(),
                'updated_by' => $usuario->id,
            ]);

            return $actual->load('cliente', 'items.producto', 'items.servicio');
        });
    }
}
