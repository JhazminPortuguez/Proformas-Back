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

        DB::statement("ALTER TABLE solicitudes_cotizacion ADD CONSTRAINT solicitudes_cotizacion_estado_check CHECK (estado IN ('PENDIENTE', 'ATENDIDA', 'RECHAZADA'))");
        DB::statement("ALTER TABLE proformas ADD CONSTRAINT proformas_origen_check CHECK (origen IN ('INTERNA', 'SOLICITUD'))");
        DB::statement('ALTER TABLE solicitud_items ADD CONSTRAINT solicitud_items_cantidad_check CHECK (cantidad > 0)');
        DB::statement('ALTER TABLE productos ADD CONSTRAINT productos_stock_check CHECK (stock_actual >= 0 AND stock_minimo >= 0 AND costo_promedio >= 0)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE solicitudes_cotizacion DROP CONSTRAINT IF EXISTS solicitudes_cotizacion_estado_check');
        DB::statement('ALTER TABLE proformas DROP CONSTRAINT IF EXISTS proformas_origen_check');
        DB::statement('ALTER TABLE solicitud_items DROP CONSTRAINT IF EXISTS solicitud_items_cantidad_check');
        DB::statement('ALTER TABLE productos DROP CONSTRAINT IF EXISTS productos_stock_check');
    }
};
