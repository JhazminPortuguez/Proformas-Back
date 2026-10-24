<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servicios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->default(1)->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 200);
            $table->text('descripcion')->nullable();
            $table->decimal('precio_base', 12, 2);
            $table->unsignedSmallInteger('duracion_estimada_min')->nullable();
            $table->string('categoria', 80)->nullable();
            $table->boolean('activo')->default(true);
            $table->boolean('visible_catalogo')->default(true);
            $table->timestamps();

            $table->index('nombre');
            $table->index('activo');
            $table->index('visible_catalogo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servicios');
    }
};
