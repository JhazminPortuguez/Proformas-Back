<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')
                ->constrained('empresas')
                ->restrictOnDelete();
            $table->foreignId('producto_id')
                ->constrained('productos')
                ->restrictOnDelete();
            $table->foreignId('orden_id')
                ->nullable()
                ->constrained('ordenes_servicio')
                ->nullOnDelete();
            $table->foreignId('orden_item_id')
                ->nullable()
                ->constrained('orden_items')
                ->nullOnDelete();
            $table->string('tipo', 20);
            $table->decimal('cantidad', 12, 3);
            $table->decimal('costo_unitario', 12, 2)->nullable()->default(0);
            $table->decimal('stock_anterior', 12, 3);
            $table->decimal('stock_nuevo', 12, 3);
            $table->text('motivo')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['empresa_id', 'producto_id'], 'mov_inv_empresa_producto_idx');
            $table->index('producto_id', 'mov_inv_producto_idx');
            $table->index(['empresa_id', 'tipo'], 'mov_inv_empresa_tipo_idx');
            $table->index(['empresa_id', 'created_at'], 'mov_inv_empresa_fecha_idx');
            $table->index('orden_id', 'mov_inv_orden_idx');
            $table->index('orden_item_id', 'mov_inv_orden_item_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_tipo_check CHECK (tipo IN (\'ENTRADA\', \'SALIDA\', \'AJUSTE\', \'CONSUMO\'))');
            DB::statement('ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_cantidad_check CHECK (cantidad <> 0)');
            DB::statement('ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_stock_check CHECK (stock_anterior >= 0 AND stock_nuevo >= 0)');
            DB::statement('ALTER TABLE movimientos_inventario ADD CONSTRAINT movimientos_inventario_costo_check CHECK (costo_unitario IS NULL OR costo_unitario >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
