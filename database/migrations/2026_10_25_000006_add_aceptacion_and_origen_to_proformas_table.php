<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proformas', function (Blueprint $table): void {
            $table->timestamp('enviada_at')->nullable()->after('fecha_validez');
            $table->timestamp('aceptada_at')->nullable()->after('enviada_at');
            $table->foreignId('aceptada_por')->nullable()->after('aceptada_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('rechazada_at')->nullable()->after('aceptada_por');
            $table->text('motivo_rechazo')->nullable()->after('rechazada_at');
            $table->string('origen', 12)->default('INTERNA')->after('motivo_rechazo');
            $table->foreignId('solicitud_id')->nullable()->after('origen')
                ->constrained('solicitudes_cotizacion')->nullOnDelete();

            $table->index('origen');
            $table->index('solicitud_id');
            $table->index('aceptada_por');
        });
    }

    public function down(): void
    {
        Schema::table('proformas', function (Blueprint $table): void {
            $table->dropForeign(['aceptada_por']);
            $table->dropForeign(['solicitud_id']);
            $table->dropIndex(['origen']);
            $table->dropIndex(['solicitud_id']);
            $table->dropIndex(['aceptada_por']);
            $table->dropColumn([
                'enviada_at',
                'aceptada_at',
                'aceptada_por',
                'rechazada_at',
                'motivo_rechazo',
                'origen',
                'solicitud_id',
            ]);
        });
    }
};
