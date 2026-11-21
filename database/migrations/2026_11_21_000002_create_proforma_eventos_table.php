<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de envíos por correo de una proforma (ronda A, parte 2): enviado,
 * fallido o sin correo registrado. Un reenvío agrega otra fila.
 *
 * Tabla nueva: no cambia datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proforma_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proforma_id')->constrained('proformas')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->string('destinatario')->nullable();
            $table->text('detalle')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['proforma_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_eventos');
    }
};
