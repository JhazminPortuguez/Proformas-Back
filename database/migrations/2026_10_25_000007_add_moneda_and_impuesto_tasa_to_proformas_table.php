<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proformas', function (Blueprint $table): void {
            $table->string('moneda', 3)->default('BOB')->after('estado');
            $table->decimal('impuesto_tasa', 5, 2)->default(0)->after('moneda');
        });
    }

    public function down(): void
    {
        Schema::table('proformas', function (Blueprint $table): void {
            $table->dropColumn(['moneda', 'impuesto_tasa']);
        });
    }
};
