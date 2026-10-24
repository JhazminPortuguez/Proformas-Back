<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ALTER COLUMN rol SET DEFAULT 'cliente'");

            return;
        }

        // SQLite no ejecuta el ALTER de PostgreSQL; se normaliza el default
        // mediante el schema builder para mantener el mismo contrato.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('rol', 20)->default('cliente')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ALTER COLUMN rol SET DEFAULT 'vendedor'");

            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('rol', 20)->default('vendedor')->change();
        });
    }
};
