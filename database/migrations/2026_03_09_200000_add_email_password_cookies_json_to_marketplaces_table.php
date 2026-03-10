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
        Schema::table('marketplaces', function (Blueprint $table) {
            $table->string('email')->nullable()->after('session_data');
            $table->string('password')->nullable()->after('email');
            $table->longText('cookies_json')->nullable()->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplaces', function (Blueprint $table) {
            $table->dropColumn(['email', 'password', 'cookies_json']);
        });
    }
};
