<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda C, parte 1: la promoción de cada línea viaja con la venta.
 *
 * - proforma_items y orden_items: la promoción elegida y una copia de su
 *   nombre, tipo y valor (si la promoción cambia después, la línea no) y el
 *   descuento de la línea. `total_linea` pasa a ser el importe ya descontado.
 * - proformas y cuentas_por_cobrar: el total descontado por promociones.
 *
 * Solo columnas nuevas, con valor por defecto 0 o null: las filas existentes
 * no tienen promoción y sus importes no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['proforma_items', 'orden_items'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->foreignId('promocion_id')->nullable()->constrained('promociones')->restrictOnDelete();
                $table->string('promocion_nombre', 120)->nullable();
                $table->string('promocion_tipo', 20)->nullable();
                $table->decimal('promocion_valor', 12, 2)->nullable();
                $table->decimal('descuento_promocion', 12, 2)->default(0);
            });
        }

        Schema::table('proformas', function (Blueprint $table): void {
            $table->decimal('descuento_promocion', 12, 2)->default(0);
        });

        Schema::table('cuentas_por_cobrar', function (Blueprint $table): void {
            $table->decimal('descuento_promocion', 12, 2)->default(0);
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['proforma_items', 'orden_items', 'proformas', 'cuentas_por_cobrar'] as $tabla) {
                DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT {$tabla}_descuento_promocion_check CHECK (descuento_promocion >= 0)");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['proforma_items', 'orden_items', 'proformas', 'cuentas_por_cobrar'] as $tabla) {
                DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$tabla}_descuento_promocion_check");
            }
        }

        foreach (['proforma_items', 'orden_items'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('promocion_id');
                $table->dropColumn(['promocion_nombre', 'promocion_tipo', 'promocion_valor', 'descuento_promocion']);
            });
        }

        Schema::table('proformas', fn (Blueprint $table) => $table->dropColumn('descuento_promocion'));
        Schema::table('cuentas_por_cobrar', fn (Blueprint $table) => $table->dropColumn('descuento_promocion'));
    }
};
