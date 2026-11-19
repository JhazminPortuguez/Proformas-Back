<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C-05: ediciones concurrentes de un cliente (bloqueo optimista).
 *
 * - `version`: sube en cada guardado; el formulario manda la versión con la
 *   que se abrió y, si otro la cambió entre tanto, el back responde 409.
 *   No sirve `updated_at`: tiene precisión de segundos.
 * - `updated_by`: quién guardó por última vez, para el mensaje del 409.
 *
 * No cambia datos: los clientes existentes quedan en versión 1 y sin
 * `updated_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('estado');
            $table->foreignId('updated_by')->nullable()->after('version')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn('version');
        });
    }
};
