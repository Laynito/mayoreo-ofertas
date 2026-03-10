<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar columna hash de url_producto para índice único eficiente
        Schema::table('productos', function (Blueprint $table) {
            $table->char('url_producto_hash', 64)->nullable()->after('url_producto');
        });

        // Rellenar el hash para los registros existentes
        DB::statement("UPDATE productos SET url_producto_hash = SHA2(url_producto, 256) WHERE url_producto IS NOT NULL AND url_producto != ''");

        // Crear índice único sobre el hash
        Schema::table('productos', function (Blueprint $table) {
            $table->unique('url_producto_hash', 'productos_url_producto_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropUnique('productos_url_producto_hash_unique');
            $table->dropColumn('url_producto_hash');
        });
    }
};
