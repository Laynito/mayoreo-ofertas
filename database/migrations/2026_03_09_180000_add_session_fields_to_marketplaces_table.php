<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Credenciales y sesión para Walmart (y otros): email/contraseña y cookies guardadas por el scraper.
     */
    public function up(): void
    {
        Schema::table('marketplaces', function (Blueprint $table) {
            $table->string('affiliate_user')->nullable()->after('app_id');
            $table->string('affiliate_password')->nullable()->after('affiliate_user');
            $table->text('session_data')->nullable()->after('affiliate_password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplaces', function (Blueprint $table) {
            $table->dropColumn(['affiliate_user', 'affiliate_password', 'session_data']);
        });
    }
};
