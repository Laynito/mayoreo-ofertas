<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Origen del rastreo: 'API' (promociones ML) o 'Scraping' (página HTML).
     * Permite saber en NotificacionLog si la oferta vino de la API o del scraping.
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('origen_rastreo', 20)->nullable()->after('activo')->comment('API|Scraping');
        });

        Schema::table('notificacion_logs', function (Blueprint $table) {
            $table->string('origen_rastreo', 20)->nullable()->after('enlace_generado')->comment('API|Scraping');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('origen_rastreo');
        });
        Schema::table('notificacion_logs', function (Blueprint $table) {
            $table->dropColumn('origen_rastreo');
        });
    }
};
