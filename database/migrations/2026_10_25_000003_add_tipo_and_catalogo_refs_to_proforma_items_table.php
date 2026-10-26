<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proforma_items', function (Blueprint $table): void {
            $table->string('tipo', 10)->default('MATERIAL')->after('proforma_id');
            $table->foreignId('servicio_id')->nullable()->after('producto_id')
                ->constrained('servicios')->restrictOnDelete();
            $table->string('nombre', 200)->nullable()->after('descripcion');
            $table->string('unidad_medida', 30)->nullable()->after('nombre');

            $table->index('tipo');
            $table->index('servicio_id');
        });

        // El producto pasa a ser opcional (las líneas de servicio y libres no
        // llevan producto). La cantidad admite hasta tres decimales.
        Schema::table('proforma_items', function (Blueprint $table): void {
            $table->foreignId('producto_id')->nullable()->change();
            $table->decimal('cantidad', 12, 3)->default(1)->change();
        });

        $this->completarSnapshots();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE proforma_items ADD CONSTRAINT proforma_items_tipo_check CHECK (tipo IN ('SERVICIO', 'MATERIAL', 'LIBRE'))");

            DB::statement(<<<'SQL'
                ALTER TABLE proforma_items ADD CONSTRAINT proforma_items_referencia_check CHECK (
                    (tipo = 'MATERIAL' AND producto_id IS NOT NULL AND servicio_id IS NULL)
                    OR (tipo = 'SERVICIO' AND servicio_id IS NOT NULL AND producto_id IS NULL)
                    OR (
                        tipo = 'LIBRE'
                        AND producto_id IS NULL
                        AND servicio_id IS NULL
                        AND descripcion IS NOT NULL
                        AND btrim(descripcion) <> ''
                        AND precio_unitario >= 0
                    )
                )
            SQL);

            DB::statement('ALTER TABLE proforma_items ADD CONSTRAINT proforma_items_cantidad_check CHECK (cantidad > 0)');
            DB::statement('ALTER TABLE proforma_items ADD CONSTRAINT proforma_items_precio_check CHECK (precio_unitario >= 0)');
            DB::statement('ALTER TABLE proforma_items ADD CONSTRAINT proforma_items_total_check CHECK (total_linea >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE proforma_items DROP CONSTRAINT IF EXISTS proforma_items_tipo_check');
            DB::statement('ALTER TABLE proforma_items DROP CONSTRAINT IF EXISTS proforma_items_referencia_check');
            DB::statement('ALTER TABLE proforma_items DROP CONSTRAINT IF EXISTS proforma_items_cantidad_check');
            DB::statement('ALTER TABLE proforma_items DROP CONSTRAINT IF EXISTS proforma_items_precio_check');
            DB::statement('ALTER TABLE proforma_items DROP CONSTRAINT IF EXISTS proforma_items_total_check');
        }

        Schema::table('proforma_items', function (Blueprint $table): void {
            $table->dropIndex(['tipo']);
            $table->dropIndex(['servicio_id']);
            $table->dropForeign(['servicio_id']);
            $table->dropColumn(['tipo', 'servicio_id', 'nombre', 'unidad_medida']);
        });

        Schema::table('proforma_items', function (Blueprint $table): void {
            $table->foreignId('producto_id')->nullable(false)->change();
            $table->unsignedInteger('cantidad')->change();
        });
    }

    private function completarSnapshots(): void
    {
        DB::table('proforma_items')
            ->whereNull('nombre')
            ->orderBy('id')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    $producto = DB::table('productos')
                        ->where('id', $item->producto_id)
                        ->first(['nombre', 'unidad_medida']);

                    if ($producto === null) {
                        continue;
                    }

                    DB::table('proforma_items')
                        ->where('id', $item->id)
                        ->update([
                            'nombre' => $producto->nombre,
                            'unidad_medida' => $producto->unidad_medida,
                        ]);
                }
            });
    }
};
