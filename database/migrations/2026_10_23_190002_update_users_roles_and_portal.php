<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El rol vendedor desaparece: pasa a operario.
        DB::table('users')->where('rol', 'vendedor')->update(['rol' => 'operario']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_rol_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IN ('admin', 'operario', 'cliente'))");
            DB::statement("ALTER TABLE users ALTER COLUMN rol SET DEFAULT 'cliente'");
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('activo')->default(true)->after('rol')->index();
            $table->foreignId('cliente_id')->nullable()->after('empresa_id')->index();
        });

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreign('cliente_id')
                    ->references('id')
                    ->on('clientes')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropForeign(['cliente_id']);
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['activo', 'cliente_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_rol_check');
            DB::statement("UPDATE users SET rol = 'operario' WHERE rol = 'cliente'");
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IN ('admin', 'vendedor', 'operario'))");
            DB::statement("ALTER TABLE users ALTER COLUMN rol SET DEFAULT 'vendedor'");
        }
    }
};
