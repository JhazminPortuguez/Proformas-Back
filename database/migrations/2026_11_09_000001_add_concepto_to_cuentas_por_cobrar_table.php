<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Concepto de la cuenta por cobrar: SERVICIO (orden cerrada, la de siempre) o
 * CANCELACION (materiales usados de una orden cancelada). Las cuentas que ya
 * existen quedan como SERVICIO por el valor por defecto. Se mantiene una
 * cuenta por orden: una orden cancelada nunca llega a cerrarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_por_cobrar', function (Blueprint $table): void {
            $table->string('concepto', 20)->default('SERVICIO')->after('cliente_id');
            $table->index('concepto');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cuentas_por_cobrar ADD CONSTRAINT cuentas_por_cobrar_concepto_check CHECK (concepto IN ('SERVICIO', 'CANCELACION'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cuentas_por_cobrar DROP CONSTRAINT IF EXISTS cuentas_por_cobrar_concepto_check');
        }

        Schema::table('cuentas_por_cobrar', function (Blueprint $table): void {
            $table->dropIndex(['concepto']);
            $table->dropColumn('concepto');
        });
    }
};
