<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_cuenta', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('cuenta_id')->constrained('cuentas_por_cobrar')->cascadeOnDelete();
            $table->decimal('monto', 12, 2);
            $table->string('metodo', 20);
            $table->date('fecha_pago');
            $table->string('referencia', 100)->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->index('empresa_id');
            $table->index('cuenta_id');
            $table->index('metodo');
            $table->index('fecha_pago');
            $table->index('anulado_at');
            $table->index('created_by');
            $table->index('anulado_por');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_cuenta');
    }
};
