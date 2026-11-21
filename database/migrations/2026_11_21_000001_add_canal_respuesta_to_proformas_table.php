<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-11 / U-05: el cliente acepta o rechaza su proforma desde el portal.
 *
 * - `aceptada_canal` y `rechazada_canal`: PORTAL o INTERNO.
 * - `rechazada_por`: quién registró el rechazo (como `aceptada_por`).
 *
 * No cambia datos: las respuestas anteriores quedan sin canal, y el front
 * las muestra como registradas por el personal (antes solo existía esa vía).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proformas', function (Blueprint $table): void {
            $table->string('aceptada_canal', 10)->nullable()->after('aceptada_por');
            $table->foreignId('rechazada_por')->nullable()->after('rechazada_at')->constrained('users')->nullOnDelete();
            $table->string('rechazada_canal', 10)->nullable()->after('rechazada_por');
        });
    }

    public function down(): void
    {
        Schema::table('proformas', function (Blueprint $table): void {
            $table->dropColumn('rechazada_canal');
            $table->dropConstrainedForeignId('rechazada_por');
            $table->dropColumn('aceptada_canal');
        });
    }
};
