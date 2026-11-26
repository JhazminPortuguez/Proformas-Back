<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\EstadoProforma;
use App\Models\Proforma;
use App\Models\Promocion;
use App\Models\User;
use App\Support\FechaBolivia;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EnviarProforma
{
    public const MENSAJE_TOTAL_CERO = 'La proforma debe tener un total mayor a Bs 0 para enviarse.';

    public const MENSAJE_VENCIDA = 'Actualiza la fecha de validez antes de enviar.';

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

            if ((float) $actual->total <= 0) {
                throw ValidationException::withMessages([
                    'total' => self::MENSAJE_TOTAL_CERO,
                ]);
            }

            // P-15: una proforma con la validez ya vencida (hora de Bolivia)
            // no se envía. Aceptar una enviada y vencida sí se permite, con aviso.
            if ($actual->fecha_validez !== null && $actual->fecha_validez->toDateString() < FechaBolivia::hoyTexto()) {
                throw ValidationException::withMessages([
                    'fecha_validez' => self::MENSAJE_VENCIDA,
                ]);
            }

            // Ronda C: si una promoción de sus líneas venció o se desactivó
            // antes de enviarla, la proforma no se envía.
            $this->validarPromociones($actual);

            $actual->update([
                'estado' => EstadoProforma::Enviada,
                'enviada_at' => now(),
                'updated_by' => $usuario->id,
            ]);

            return $actual->load('cliente', 'items.producto', 'items.servicio');
        });
    }

    private function validarPromociones(Proforma $proforma): void
    {
        $ids = $proforma->items()->whereNotNull('promocion_id')->pluck('promocion_id')->unique();

        if ($ids->isEmpty()) {
            return;
        }

        $motivos = Promocion::query()
            ->whereIn('id', $ids)
            ->orderBy('nombre')
            ->get()
            ->map(fn (Promocion $promocion): ?string => $promocion->motivoNoVigente())
            ->filter()
            ->values();

        if ($motivos->isNotEmpty()) {
            throw ValidationException::withMessages([
                'promocion' => $motivos->implode(' ').' Edita la proforma y quita la promoción antes de enviarla.',
            ]);
        }
    }
}
