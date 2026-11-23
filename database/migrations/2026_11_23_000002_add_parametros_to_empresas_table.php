<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda B, parte 1: parámetros del sistema que edita el superusuario. Son
 * los valores iniciales del formulario de nueva proforma.
 *
 * Los valores por defecto repiten lo que el formulario ya hacía (13 % de
 * impuesto y la validez en blanco): la empresa existente no cambia de
 * comportamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->decimal('impuesto_por_defecto', 5, 2)->default(13)->after('condiciones');
            $table->unsignedSmallInteger('dias_validez_por_defecto')->nullable()->after('impuesto_por_defecto');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE empresas ADD CONSTRAINT empresas_impuesto_por_defecto_check CHECK (impuesto_por_defecto >= 0 AND impuesto_por_defecto <= 100)');
            DB::statement('ALTER TABLE empresas ADD CONSTRAINT empresas_dias_validez_por_defecto_check CHECK (dias_validez_por_defecto IS NULL OR (dias_validez_por_defecto >= 1 AND dias_validez_por_defecto <= 365))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE empresas DROP CONSTRAINT IF EXISTS empresas_impuesto_por_defecto_check');
            DB::statement('ALTER TABLE empresas DROP CONSTRAINT IF EXISTS empresas_dias_validez_por_defecto_check');
        }

        Schema::table('empresas', function (Blueprint $table): void {
            $table->dropColumn(['impuesto_por_defecto', 'dias_validez_por_defecto']);
        });
    }
};
