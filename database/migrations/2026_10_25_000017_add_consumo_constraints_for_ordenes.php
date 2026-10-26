<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE orden_eventos DROP CONSTRAINT IF EXISTS orden_eventos_tipo_check');
        DB::statement("ALTER TABLE orden_eventos ADD CONSTRAINT orden_eventos_tipo_check CHECK (tipo IN ('CREACION', 'ASIGNACION', 'INICIO', 'FINALIZACION', 'CANCELACION', 'CIERRE', 'CONSUMO'))");

        DB::statement('ALTER TABLE orden_items DROP CONSTRAINT IF EXISTS orden_items_cantidad_consumida_check');
        DB::statement('ALTER TABLE orden_items ADD CONSTRAINT orden_items_cantidad_consumida_check CHECK (cantidad_consumida >= 0 AND cantidad_consumida <= cantidad)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE orden_items DROP CONSTRAINT IF EXISTS orden_items_cantidad_consumida_check');
        DB::statement('ALTER TABLE orden_eventos DROP CONSTRAINT IF EXISTS orden_eventos_tipo_check');
        DB::statement("ALTER TABLE orden_eventos ADD CONSTRAINT orden_eventos_tipo_check CHECK (tipo IN ('CREACION', 'ASIGNACION', 'INICIO', 'FINALIZACION', 'CANCELACION', 'CIERRE'))");
    }
};
