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
        Schema::create('proforma_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('proforma_id')->constrained('proformas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');

            $table->string('descripcion', 255)->nullable(); // por si se personaliza el ítem
            $table->unsignedInteger('cantidad');
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('total_linea', 12, 2);

            $table->timestamps();
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proforma_items');
    }
};
