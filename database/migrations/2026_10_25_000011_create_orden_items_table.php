<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('orden_id')->constrained('ordenes_servicio')->cascadeOnDelete();
            $table->string('tipo', 10)->default('MATERIAL');
            $table->foreignId('servicio_id')->nullable()->constrained('servicios')->nullOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->text('descripcion');
            $table->string('unidad_medida', 30)->nullable();
            $table->decimal('cantidad', 12, 3)->default(1);
            $table->decimal('precio_unitario', 12, 2)->default(0);
            $table->decimal('total_linea', 12, 2)->default(0);
            $table->timestamps();

            $table->index('orden_id');
            $table->index('tipo');
            $table->index('servicio_id');
            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_items');
    }
};
