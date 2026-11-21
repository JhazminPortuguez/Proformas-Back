<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ronda A, parte 3: desde ahora el cliente verifica su correo antes de enviar
 * solicitudes o responder proformas. Para no bloquear a los que ya existían,
 * los usuarios con rol cliente creados ANTES de esta migración quedan
 * verificados. Es la única excepción aprobada a "no tocar datos" de la ronda.
 *
 * Solo cambia `email_verified_at` de usuarios cliente que lo tenían vacío.
 * Los creados después (registro en el portal) reciben el enlace por correo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();

        $cambiadas = DB::table('users')
            ->where('rol', 'cliente')
            ->whereNull('email_verified_at')
            ->where('created_at', '<=', $ahora)
            ->update(['email_verified_at' => $ahora]);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "  Clientes existentes marcados como verificados: {$cambiadas}".PHP_EOL;
        }
    }

    public function down(): void
    {
        // No se revierte: no se sabe cuáles ya estaban verificados antes.
    }
};
