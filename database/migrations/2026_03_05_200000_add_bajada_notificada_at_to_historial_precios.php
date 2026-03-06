<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca qué registros del historial ya generaron una notificación de bajada,
     * para no reenviar la misma oferta cada vez que corre el Job.
     */
    public function up(): void
    {
        Schema::table('historial_precios', function (Blueprint $table) {
            $table->timestamp('bajada_notificada_at')->nullable()->after('registrado_en');
        });
    }

    public function down(): void
    {
        Schema::table('historial_precios', function (Blueprint $table) {
            $table->dropColumn('bajada_notificada_at');
        });
    }
};
