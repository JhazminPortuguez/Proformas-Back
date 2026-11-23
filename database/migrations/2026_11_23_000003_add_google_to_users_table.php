<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda B, parte 2: "Continuar con Google".
 *
 * - google_id: el identificador de la cuenta de Google con la que entró.
 * - datos_pendientes: la cuenta se creó con Google y le faltan el teléfono
 *   o el NIT/CI; hasta completarlos no envía solicitudes.
 *
 * Solo estructura: los usuarios existentes quedan con null y false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_id', 64)->nullable()->unique()->after('email');
            $table->boolean('datos_pendientes')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'datos_pendientes']);
        });
    }
};
