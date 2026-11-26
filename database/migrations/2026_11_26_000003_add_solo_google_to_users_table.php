<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda C, parte 2: "Olvidé mi contraseña". `solo_google` marca las cuentas
 * creadas con "Continuar con Google": no tienen una contraseña que conozcan,
 * así que en vez de un enlace reciben un correo que les dice que entren con
 * Google.
 *
 * Solo estructura: los usuarios existentes quedan con false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('solo_google')->default(false)->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('solo_google');
        });
    }
};
