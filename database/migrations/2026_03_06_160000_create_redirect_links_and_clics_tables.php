<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enlaces de redirección (mayoreo.cloud/r/{codigo}) y registro de clics para estadísticas.
     * subid se guarda igual que el enviado a Admitad para consistencia.
     */
    public function up(): void
    {
        Schema::create('redirect_links', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 32)->unique();
            $table->string('url_destino', 2048);
            $table->string('subid', 120)->default('Telegram_Bot');
            $table->timestamps();
        });

        Schema::create('clics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('redirect_link_id')->constrained('redirect_links')->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('clicked_at');
            $table->timestamps();

            $table->index('redirect_link_id');
            $table->index('clicked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clics');
        Schema::dropIfExists('redirect_links');
    }
};
