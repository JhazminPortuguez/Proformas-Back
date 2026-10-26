<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_items', function (Blueprint $table): void {
            $table->decimal('cantidad_consumida', 12, 3)
                ->default(0)
                ->after('cantidad');
            $table->dateTime('consumido_at')
                ->nullable()
                ->after('cantidad_consumida');
        });
    }

    public function down(): void
    {
        Schema::table('orden_items', function (Blueprint $table): void {
            $table->dropColumn(['cantidad_consumida', 'consumido_at']);
        });
    }
};
