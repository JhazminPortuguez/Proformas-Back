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
        Schema::create('clientes', function (Blueprint $table) {
                $table->id();
                $table->string('nombre_razon', 200);
                $table->string('nit_ci', 30)->nullable();
                $table->string('telefono', 30)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('direccion', 255)->nullable();
                $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
