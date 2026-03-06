<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Atribución de afiliados: affiliate_url = URL final procesada (con micosmtics).
     * activo = false cuando producto sin precio o "No disponible" (no enviar enlaces rotos a Telegram).
     */
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->string('affiliate_url', 2048)->nullable()->after('url_afiliado');
            $table->boolean('activo')->default(true)->after('permite_descuento_adicional');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['affiliate_url', 'activo']);
        });
    }
};
