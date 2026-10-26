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

        DB::statement("ALTER TABLE ordenes_servicio ADD CONSTRAINT ordenes_servicio_estado_check CHECK (estado IN ('PENDIENTE', 'ASIGNADA', 'EN_PROCESO', 'FINALIZADA', 'CANCELADA'))");
        DB::statement("ALTER TABLE orden_items ADD CONSTRAINT orden_items_tipo_check CHECK (tipo IN ('SERVICIO', 'MATERIAL', 'LIBRE'))");
        DB::statement('ALTER TABLE orden_items ADD CONSTRAINT orden_items_cantidad_check CHECK (cantidad > 0)');
        DB::statement('ALTER TABLE orden_items ADD CONSTRAINT orden_items_precio_check CHECK (precio_unitario >= 0 AND total_linea >= 0)');
        DB::statement("ALTER TABLE orden_eventos ADD CONSTRAINT orden_eventos_tipo_check CHECK (tipo IN ('CREACION', 'ASIGNACION', 'INICIO', 'FINALIZACION', 'CANCELACION', 'CIERRE'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE ordenes_servicio DROP CONSTRAINT IF EXISTS ordenes_servicio_estado_check');
        DB::statement('ALTER TABLE orden_items DROP CONSTRAINT IF EXISTS orden_items_tipo_check');
        DB::statement('ALTER TABLE orden_items DROP CONSTRAINT IF EXISTS orden_items_cantidad_check');
        DB::statement('ALTER TABLE orden_items DROP CONSTRAINT IF EXISTS orden_items_precio_check');
        DB::statement('ALTER TABLE orden_eventos DROP CONSTRAINT IF EXISTS orden_eventos_tipo_check');
    }
};
