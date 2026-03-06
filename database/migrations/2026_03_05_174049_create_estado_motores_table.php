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
        Schema::create('estado_motores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_tienda', 80)->unique()->comment('Nombre de la tienda como en RastreadorFabrica');
            $table->string('estado', 20)->default('activo')->comment('activo|bloqueado|fallo_temporal');
            $table->text('ultimo_error')->nullable();
            $table->timestamp('ultima_actualizacion')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estado_motores');
    }
};
