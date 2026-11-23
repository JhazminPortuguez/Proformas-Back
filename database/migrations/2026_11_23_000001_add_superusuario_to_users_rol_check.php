<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ronda B, parte 1: el rol técnico "superusuario". Solo cambia el CHECK de
 * PostgreSQL (en SQLite la columna es texto libre); no toca filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_rol_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IN ('superusuario', 'admin', 'operario', 'cliente'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_rol_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IN ('admin', 'operario', 'cliente'))");
    }
};
