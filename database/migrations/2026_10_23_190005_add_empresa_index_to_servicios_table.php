<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servicios', function (Blueprint $table): void {
            $table->index('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::table('servicios', function (Blueprint $table): void {
            $table->dropIndex(['empresa_id']);
        });
    }
};
