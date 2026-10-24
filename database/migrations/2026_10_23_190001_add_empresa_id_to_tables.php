<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade empresa_id a las tablas operativas existentes y ajusta la
     * numeración de proformas para que sea única por empresa y año.
     */
    public function up(): void
    {
        $tablas = ['users', 'clientes', 'productos', 'proformas'];

        foreach ($tablas as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->foreignId('empresa_id')
                    ->default(1)
                    ->after('id')
                    ->index();
            });
        }

        // SQLite no permite agregar FKs a tablas existentes mediante ALTER.
        if (DB::getDriverName() === 'pgsql') {
            foreach ($tablas as $tabla) {
                Schema::table($tabla, function (Blueprint $table): void {
                    $table->foreign('empresa_id')
                        ->references('id')
                        ->on('empresas')
                        ->restrictOnDelete();
                });
            }
        }

        foreach ($tablas as $tabla) {
            DB::table($tabla)->whereNull('empresa_id')->update(['empresa_id' => 1]);
        }

        Schema::table('proforma_sequences', function (Blueprint $table): void {
            $table->dropUnique(['anio']);
            $table->foreignId('empresa_id')->default(1)->after('id')->index();
            $table->unique(['empresa_id', 'anio']);
        });

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('proforma_sequences', function (Blueprint $table): void {
                $table->foreign('empresa_id')
                    ->references('id')
                    ->on('empresas')
                    ->restrictOnDelete();
            });
        }

        DB::table('proforma_sequences')->whereNull('empresa_id')->update(['empresa_id' => 1]);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('proforma_sequences', function (Blueprint $table): void {
                $table->dropForeign(['empresa_id']);
            });
        }

        Schema::table('proforma_sequences', function (Blueprint $table): void {
            $table->dropUnique(['empresa_id', 'anio']);
            $table->dropColumn('empresa_id');
            $table->unique('anio');
        });

        $tablas = ['users', 'clientes', 'productos', 'proformas'];

        if (DB::getDriverName() === 'pgsql') {
            foreach ($tablas as $tabla) {
                Schema::table($tabla, function (Blueprint $table): void {
                    $table->dropForeign(['empresa_id']);
                });
            }
        }

        foreach ($tablas as $tabla) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->dropColumn('empresa_id');
            });
        }
    }
};
