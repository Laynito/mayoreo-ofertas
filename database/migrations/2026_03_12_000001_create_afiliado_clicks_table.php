<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('afiliado_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('marketplace_slug', 64)->nullable()->comment('mercado_libre, coppel, walmart, etc.');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('afiliado_clicks', function (Blueprint $table) {
            $table->index(['marketplace_slug', 'created_at']);
            $table->index('producto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('afiliado_clicks');
    }
};
