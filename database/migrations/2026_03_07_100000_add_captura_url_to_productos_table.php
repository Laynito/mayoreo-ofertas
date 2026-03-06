<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * URL o ruta de la captura de pantalla del producto (Browsershot) para mostrar en el sitio.
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('captura_url', 2048)->nullable()->after('imagen_url');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('captura_url');
        });
    }
};
