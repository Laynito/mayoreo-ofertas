<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('sku')->unique();
            $table->decimal('precio_actual', 10, 2);
            $table->decimal('precio_original', 10, 2)->nullable();
            $table->integer('descuento')->default(0);
            $table->text('url_producto');
            $table->text('url_imagen')->nullable();
            $table->string('tienda')->default('Mercado Libre');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
