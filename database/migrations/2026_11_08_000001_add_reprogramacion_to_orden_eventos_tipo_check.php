<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Solo amplía la lista del CHECK de orden_eventos.tipo con REPROGRAMACION.
 * No agrega columnas ni toca datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE orden_eventos DROP CONSTRAINT IF EXISTS orden_eventos_tipo_check');
        DB::statement("ALTER TABLE orden_eventos ADD CONSTRAINT orden_eventos_tipo_check CHECK (tipo IN ('CREACION', 'ASIGNACION', 'INICIO', 'FINALIZACION', 'CANCELACION', 'CIERRE', 'CONSUMO', 'REPROGRAMACION'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE orden_eventos DROP CONSTRAINT IF EXISTS orden_eventos_tipo_check');
        DB::statement("ALTER TABLE orden_eventos ADD CONSTRAINT orden_eventos_tipo_check CHECK (tipo IN ('CREACION', 'ASIGNACION', 'INICIO', 'FINALIZACION', 'CANCELACION', 'CIERRE', 'CONSUMO'))");
    }
};
