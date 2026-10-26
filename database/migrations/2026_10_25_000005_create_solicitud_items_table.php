<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitud_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes_cotizacion')->cascadeOnDelete();
            $table->string('descripcion', 500);
            $table->decimal('cantidad', 12, 3)->default(1);
            $table->string('unidad_medida', 30)->nullable();
            $table->string('observaciones', 500)->nullable();
            $table->timestamps();

            $table->index('solicitud_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_items');
    }
};
