<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('proformas', function (Blueprint $table) {
            $table->id();

            $table->string('numero', 30)->unique(); // PR-2025-000001
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();

            $table->date('fecha_emision');
            $table->date('fecha_validez')->nullable();

            $table->enum('estado', ['BORRADOR', 'ENVIADA', 'ACEPTADA', 'RECHAZADA'])->default('BORRADOR');

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('descuento', 12, 2)->default(0); // monto
            $table->decimal('impuesto', 12, 2)->default(0);  // monto
            $table->decimal('total', 12, 2)->default(0);

            $table->text('observaciones')->nullable();

            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proformas');
    }
};
