<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tipo DEVOLUCION: material consumido que vuelve al stock al cancelar una
 * orden. Solo amplía la lista del CHECK; no toca datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE movimientos_inventario DROP CONSTRAINT IF EXISTS movimientos_inventario_tipo_check');
        DB::statement("ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_tipo_check CHECK (tipo IN ('ENTRADA', 'SALIDA', 'AJUSTE', 'CONSUMO', 'DEVOLUCION'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE movimientos_inventario DROP CONSTRAINT IF EXISTS movimientos_inventario_tipo_check');
        DB::statement("ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_tipo_check CHECK (tipo IN ('ENTRADA', 'SALIDA', 'AJUSTE', 'CONSUMO'))");
    }
};
