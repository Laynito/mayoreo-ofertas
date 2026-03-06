<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de pings del webhook de Mercado Libre (topic, resource, sent_time).
     */
    public function up(): void
    {
        Schema::create('mercado_libre_webhooks_log', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 120)->index();
            $table->string('resource', 2048)->nullable();
            $table->string('sent_time', 80)->nullable()->comment('sent_time enviado por ML');
            $table->timestamp('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mercado_libre_webhooks_log');
    }
};
