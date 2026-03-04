<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de productos para rastreo de ofertas (tiendas: Walmart, Amazon, etc.).
     */
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('tienda_origen'); // ej. Walmart, Amazon
            $table->string('sku_tienda')->index();
            $table->string('nombre');
            $table->string('imagen_url', 2048)->nullable();

            $table->decimal('precio_original', 12, 2)->default(0);
            $table->decimal('precio_oferta', 12, 2)->nullable();
            $table->decimal('porcentaje_ahorro', 5, 2)->nullable();

            $table->unsignedInteger('stock_disponible')->default(0);
            $table->timestamp('ultima_actualizacion_precio')->nullable();

            $table->string('url_original', 2048)->nullable();
            $table->string('url_afiliado', 2048)->nullable(); // Enlace con ID Admitad

            // Restricción: si false, no se aplica descuento adicional al precio de oferta
            $table->boolean('permite_descuento_adicional')->default(true);

            $table->timestamps();

            $table->unique(['tienda_origen', 'sku_tienda']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
