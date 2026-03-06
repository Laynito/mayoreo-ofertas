<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiendas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 80)->unique()->comment('Ej. Costco, Mercado Libre');
            $table->string('clase_motor', 255)->comment('Clase del motor, ej. App\\Motores\\CostcoMotor');
            $table->boolean('activo')->default(true)->comment('Si está desactivada, no se rastrea');
            $table->string('url_ofertas', 2048)->nullable()->comment('URL de ofertas de la tienda');
            $table->string('selector_css_principal', 512)->nullable()->comment('Selector CSS principal para scraping');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiendas');
    }
};
