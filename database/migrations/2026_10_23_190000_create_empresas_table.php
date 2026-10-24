<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 200);
            $table->string('nit', 30)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('direccion', 255)->nullable();
            $table->timestamps();
        });

        // Garantiza la empresa por defecto incluso en instalaciones nuevas o
        // tests donde no se ejecutan seeders.
        DB::table('empresas')->updateOrInsert(
            ['id' => 1],
            [
                'nombre' => 'Empresa Demo',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "SELECT setval(pg_get_serial_sequence('empresas', 'id'), GREATEST((SELECT MAX(id) FROM empresas), 1))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
