<?php

namespace Database\Seeders;

use App\Models\Marketplace;
use Illuminate\Database\Seeder;

class MarketplaceSeeder extends Seeder
{
    /**
     * Inserta el marketplace Mercado Libre con valores de config (.env).
     * Ejecutar una vez: php artisan db:seed --class=MarketplaceSeeder
     */
    public function run(): void
    {
        Marketplace::updateOrCreate(
            ['slug' => 'mercado_libre'],
            [
                'nombre' => 'Mercado Libre México',
                'url_busqueda' => 'https://www.mercadolibre.com.mx/ofertas',
                'affiliate_id' => config('services.mercadolibre.affid'),
                'app_id' => config('services.mercadolibre.app_id'),
                'es_activo' => true,
                'configuracion' => ['matt_word' => config('services.mercadolibre.matt_word', 'mayoreo_cloud')],
            ]
        );
    }
}
