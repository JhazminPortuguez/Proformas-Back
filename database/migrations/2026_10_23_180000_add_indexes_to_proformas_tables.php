<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proformas', function (Blueprint $table) {
            $table->index('cliente_id');
            $table->index('estado');
            $table->index('fecha_emision');
        });

        Schema::table('proforma_items', function (Blueprint $table) {
            $table->index('proforma_id');
            $table->index('producto_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('rol');
        });
    }

    public function down(): void
    {
        Schema::table('proformas', function (Blueprint $table) {
            $table->dropIndex(['cliente_id']);
            $table->dropIndex(['estado']);
            $table->dropIndex(['fecha_emision']);
        });

        Schema::table('proforma_items', function (Blueprint $table) {
            $table->dropIndex(['proforma_id']);
            $table->dropIndex(['producto_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['rol']);
        });
    }
};
