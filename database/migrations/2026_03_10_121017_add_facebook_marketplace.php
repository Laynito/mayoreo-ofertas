<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('marketplaces')->where('slug', 'facebook')->exists()) {
            return;
        }
        DB::table('marketplaces')->insert([
            'nombre'        => 'Facebook',
            'slug'          => 'facebook',
            'es_activo'     => false,
            'configuracion' => json_encode([]),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('marketplaces')->where('slug', 'facebook')->delete();
    }
};
