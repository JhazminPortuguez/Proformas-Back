<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proformas', function (Blueprint $table): void {
            $table->dropUnique('proformas_numero_unique');
            $table->unique(['empresa_id', 'numero'], 'proformas_empresa_numero_unique');
        });
    }

    public function down(): void
    {
        Schema::table('proformas', function (Blueprint $table): void {
            $table->dropUnique('proformas_empresa_numero_unique');
            $table->unique('numero', 'proformas_numero_unique');
        });
    }
};
