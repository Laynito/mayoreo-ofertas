<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de precios por producto para graficar evolución (subida/bajada).
     */
    public function up(): void
    {
        Schema::create('historial_precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->onDelete('cascade');
            $table->decimal('precio_original', 12, 2);
            $table->decimal('precio_oferta', 12, 2)->nullable();
            $table->decimal('porcentaje_ahorro', 5, 2)->nullable();
            $table->timestamp('registrado_en');
            $table->timestamps();

            $table->index(['producto_id', 'registrado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_precios');
    }
};
