<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiendas', function (Blueprint $table) {
            $table->text('notas')->nullable()->after('selector_css_principal')->comment('Notas u observaciones sobre la tienda');
        });
    }

    public function down(): void
    {
        Schema::table('tiendas', function (Blueprint $table) {
            $table->dropColumn('notas');
        });
    }
};
