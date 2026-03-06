<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mensajes de oferta enviados a Telegram (message_id) para poder borrarlos después de 24 h.
     */
    public function up(): void
    {
        Schema::create('telegram_mensajes_oferta', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 64)->index();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('producto_id')->nullable();
            $table->timestamp('enviado_at')->index();
            $table->timestamps();

            $table->unique(['chat_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_mensajes_oferta');
    }
};
