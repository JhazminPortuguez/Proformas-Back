<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->decimal('stock_actual', 12, 3)->default(0)->after('unidad_medida');
            $table->decimal('stock_minimo', 12, 3)->default(0)->after('stock_actual');
            $table->decimal('costo_promedio', 12, 2)->default(0)->after('stock_minimo');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table): void {
            $table->dropColumn(['stock_actual', 'stock_minimo', 'costo_promedio']);
        });
    }
};
