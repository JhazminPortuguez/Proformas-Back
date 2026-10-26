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

        DB::statement("ALTER TABLE cuentas_por_cobrar ADD CONSTRAINT cuentas_por_cobrar_estado_check CHECK (estado_cobro IN ('PENDIENTE', 'PARCIAL', 'PAGADA'))");
        DB::statement('ALTER TABLE cuentas_por_cobrar ADD CONSTRAINT cuentas_por_cobrar_monto_check CHECK (monto_total >= 0)');
        DB::statement('ALTER TABLE cuentas_por_cobrar ADD CONSTRAINT cuentas_por_cobrar_saldo_check CHECK (saldo >= 0 AND saldo <= monto_total)');
        DB::statement('ALTER TABLE cuentas_por_cobrar ADD CONSTRAINT cuentas_por_cobrar_vencimiento_check CHECK (fecha_vencimiento >= fecha_emision)');

        DB::statement('ALTER TABLE pagos_cuenta ADD CONSTRAINT pagos_cuenta_monto_check CHECK (monto > 0)');
        DB::statement("ALTER TABLE pagos_cuenta ADD CONSTRAINT pagos_cuenta_metodo_check CHECK (metodo IN ('EFECTIVO', 'TRANSFERENCIA', 'QR', 'TARJETA', 'OTRO'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE cuentas_por_cobrar DROP CONSTRAINT IF EXISTS cuentas_por_cobrar_estado_check');
        DB::statement('ALTER TABLE cuentas_por_cobrar DROP CONSTRAINT IF EXISTS cuentas_por_cobrar_monto_check');
        DB::statement('ALTER TABLE cuentas_por_cobrar DROP CONSTRAINT IF EXISTS cuentas_por_cobrar_saldo_check');
        DB::statement('ALTER TABLE cuentas_por_cobrar DROP CONSTRAINT IF EXISTS cuentas_por_cobrar_vencimiento_check');
        DB::statement('ALTER TABLE pagos_cuenta DROP CONSTRAINT IF EXISTS pagos_cuenta_monto_check');
        DB::statement('ALTER TABLE pagos_cuenta DROP CONSTRAINT IF EXISTS pagos_cuenta_metodo_check');
    }
};
