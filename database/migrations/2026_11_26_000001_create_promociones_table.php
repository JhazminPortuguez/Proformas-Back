<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda C, parte 1: catálogo de promociones que gestiona el administrador.
 *
 * Tabla nueva: no cambia datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promociones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre', 120);
            $table->string('tipo', 20);
            // Porcentaje (1-100) o monto en Bs; null en el 2x1.
            $table->decimal('valor', 12, 2)->nullable();
            $table->string('aplica_a', 20);
            $table->foreignId('servicio_id')->nullable()->constrained('servicios')->restrictOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->restrictOnDelete();
            $table->date('vigente_desde');
            $table->date('vigente_hasta');
            $table->boolean('activa')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['empresa_id', 'activa', 'vigente_desde', 'vigente_hasta']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE promociones ADD CONSTRAINT promociones_tipo_check CHECK (tipo IN ('PORCENTAJE', 'MONTO_FIJO', 'DOS_POR_UNO'))");
            DB::statement("ALTER TABLE promociones ADD CONSTRAINT promociones_aplica_a_check CHECK (aplica_a IN ('SERVICIO', 'PRODUCTO', 'CUALQUIERA'))");
            DB::statement(<<<'SQL'
                ALTER TABLE promociones ADD CONSTRAINT promociones_valor_check CHECK (
                    (tipo = 'PORCENTAJE' AND valor >= 1 AND valor <= 100)
                    OR (tipo = 'MONTO_FIJO' AND valor > 0)
                    OR (tipo = 'DOS_POR_UNO' AND valor IS NULL)
                )
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE promociones ADD CONSTRAINT promociones_referencia_check CHECK (
                    (aplica_a = 'SERVICIO' AND servicio_id IS NOT NULL AND producto_id IS NULL)
                    OR (aplica_a = 'PRODUCTO' AND producto_id IS NOT NULL AND servicio_id IS NULL)
                    OR (aplica_a = 'CUALQUIERA' AND servicio_id IS NULL AND producto_id IS NULL)
                )
                SQL);
            DB::statement('ALTER TABLE promociones ADD CONSTRAINT promociones_vigencia_check CHECK (vigente_hasta >= vigente_desde)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promociones');
    }
};
