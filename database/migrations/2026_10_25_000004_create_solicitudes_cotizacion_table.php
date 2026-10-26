<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_cotizacion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->default(1)->constrained('empresas')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->text('descripcion');
            $table->string('estado', 20)->default('PENDIENTE');
            $table->foreignId('convertida_proforma_id')->nullable()
                ->constrained('proformas')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('estado');
            $table->index('cliente_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_cotizacion');
    }
};
