<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_por_cobrar', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('orden_id')->unique()->constrained('ordenes_servicio')->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('numero', 30);
            $table->date('fecha_emision');
            $table->date('fecha_vencimiento');
            $table->string('moneda', 3)->default('BOB');
            $table->decimal('monto_total', 12, 2)->default(0);
            $table->decimal('saldo', 12, 2)->default(0);
            $table->string('estado_cobro', 20)->default('PENDIENTE');
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->index('empresa_id');
            $table->index('cliente_id');
            $table->index('estado_cobro');
            $table->index('fecha_vencimiento');
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_por_cobrar');
    }
};
