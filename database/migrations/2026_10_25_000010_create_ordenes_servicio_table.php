<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_servicio', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('proforma_id')->constrained('proformas')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('numero', 30);
            $table->foreignId('operario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado', 20)->default('PENDIENTE');

            $table->date('fecha_programada')->nullable();
            $table->timestamp('fecha_inicio')->nullable();
            $table->timestamp('fecha_fin')->nullable();

            $table->text('observaciones')->nullable();
            $table->text('motivo_cancelacion')->nullable();
            $table->timestamp('cancelada_at')->nullable();

            $table->timestamp('cerrada_at')->nullable();
            $table->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->index('empresa_id');
            $table->index('estado');
            $table->index('operario_id');
            $table->index('cliente_id');
            $table->index('proforma_id');
            $table->index('cerrada_por');
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_servicio');
    }
};
