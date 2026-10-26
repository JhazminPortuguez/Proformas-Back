<?php

declare(strict_types=1);

namespace App\Actions\Proformas;

use App\Enums\EstadoProforma;
use App\Models\Proforma;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EliminarProforma
{
    private const TABLAS_ORDENES = ['ordenes', 'ordenes_servicio'];

    public function ejecutar(Proforma $proforma): void
    {
        DB::transaction(function () use ($proforma): void {
            $actual = Proforma::query()
                ->whereKey($proforma->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actual->estado !== EstadoProforma::Borrador) {
                abort(409, 'Solo se puede eliminar una proforma en borrador.');
            }

            if ($this->tieneOrdenAsociada($actual)) {
                abort(409, 'No se puede eliminar la proforma porque tiene una orden asociada.');
            }

            $actual->items()->delete();
            $actual->delete();
        });
    }

    private function tieneOrdenAsociada(Proforma $proforma): bool
    {
        foreach (self::TABLAS_ORDENES as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'proforma_id')) {
                continue;
            }

            if (DB::table($tabla)->where('proforma_id', $proforma->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
