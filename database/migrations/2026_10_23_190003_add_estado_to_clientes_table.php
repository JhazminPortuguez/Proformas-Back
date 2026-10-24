<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('estado', 20)->default('activo')->after('direccion')->index();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE clientes ADD CONSTRAINT clientes_estado_check CHECK (estado IN ('prospecto', 'activo', 'inactivo'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE clientes DROP CONSTRAINT IF EXISTS clientes_estado_check');
        }

        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn('estado');
        });
    }
};
