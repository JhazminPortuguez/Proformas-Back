<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * X-11: el admin puede anular una cuenta por cobrar sin pagos vigentes.
 * Agrega quién, cuándo y por qué, y el estado ANULADA al CHECK. No cambia
 * datos: las columnas nuevas quedan en null en las cuentas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_por_cobrar', function (Blueprint $table): void {
            $table->timestamp('anulada_at')->nullable()->after('estado_cobro');
            $table->foreignId('anulada_por')->nullable()->after('anulada_at')->constrained('users')->nullOnDelete();
            $table->text('motivo_anulacion')->nullable()->after('anulada_por');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cuentas_por_cobrar DROP CONSTRAINT IF EXISTS cuentas_por_cobrar_estado_check');
            DB::statement("ALTER TABLE cuentas_por_cobrar ADD CONSTRAINT cuentas_por_cobrar_estado_check CHECK (estado_cobro IN ('PENDIENTE', 'PARCIAL', 'PAGADA', 'ANULADA'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cuentas_por_cobrar DROP CONSTRAINT IF EXISTS cuentas_por_cobrar_estado_check');
            DB::statement("ALTER TABLE cuentas_por_cobrar ADD CONSTRAINT cuentas_por_cobrar_estado_check CHECK (estado_cobro IN ('PENDIENTE', 'PARCIAL', 'PAGADA'))");
        }

        Schema::table('cuentas_por_cobrar', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('anulada_por');
            $table->dropColumn(['anulada_at', 'motivo_anulacion']);
        });
    }
};
