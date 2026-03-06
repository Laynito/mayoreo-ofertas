<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificacion_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->string('tienda', 80)->nullable();
            $table->string('chat_id', 50)->nullable();
            $table->string('estado', 20)->comment('enviado|fallido|omitido');
            $table->text('mensaje_error')->nullable();
            $table->string('enlace_generado', 2048)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacion_logs');
    }
};
