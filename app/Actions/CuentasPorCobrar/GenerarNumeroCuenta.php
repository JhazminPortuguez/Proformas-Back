<?php

declare(strict_types=1);

namespace App\Actions\CuentasPorCobrar;

use App\Support\EmpresaActual;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class GenerarNumeroCuenta
{
    /**
     * Genera un correlativo por empresa y año: CXC-2026-000001.
     * Debe invocarse dentro de una transacción para mantener el bloqueo.
     */
    public function ejecutar(CarbonInterface $fecha): string
    {
        $anio = (int) $fecha->format('Y');
        $empresaId = EmpresaActual::id();

        DB::table('cuenta_sequences')->insertOrIgnore([
            'empresa_id' => $empresaId,
            'anio' => $anio,
            'ultimo_numero' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registro = DB::table('cuenta_sequences')
            ->where('empresa_id', $empresaId)
            ->where('anio', $anio)
            ->lockForUpdate()
            ->first();

        $siguiente = ((int) $registro->ultimo_numero) + 1;

        DB::table('cuenta_sequences')
            ->where('empresa_id', $empresaId)
            ->where('anio', $anio)
            ->update([
                'ultimo_numero' => $siguiente,
                'updated_at' => now(),
            ]);

        return sprintf('CXC-%d-%06d', $anio, $siguiente);
    }
}
