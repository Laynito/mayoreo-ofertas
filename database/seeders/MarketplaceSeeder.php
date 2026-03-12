<?php

namespace Database\Seeders;

use App\Models\Marketplace;
use Illuminate\Database\Seeder;

class MarketplaceSeeder extends Seeder
{
    /**
     * Inserta los marketplaces Mercado Libre y Walmart.
     * Ejecutar: php artisan db:seed --class=MarketplaceSeeder
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

        Marketplace::updateOrCreate(
            ['slug' => 'walmart'],
            [
                'nombre' => 'Walmart México',
                'url_busqueda' => 'https://www.walmart.com.mx/shop/ofertas-flash-walmart',
                'affiliate_id' => null,
                'app_id' => null,
                'es_activo' => true,
                'configuracion' => null,
            ]
        );

        Marketplace::updateOrCreate(
            ['slug' => 'elektra'],
            [
                'nombre' => 'Elektra',
                'url_busqueda' => 'https://www.elektra.mx/liquidacion',
                'affiliate_id' => null,
                'app_id' => null,
                'es_activo' => true,
                'configuracion' => [
                    'urls' => [
                        'https://www.elektra.mx/liquidacion',
                        'https://www.elektra.mx/ofertas',
                    ],
                ],
            ]
        );

        Marketplace::updateOrCreate(
            ['slug' => 'sams_club'],
            [
                'nombre' => "Sam's Club México",
                'url_busqueda' => 'https://www.sams.com.mx/browse/ofertas/3000001',
                'affiliate_id' => null,
                'app_id' => null,
                'es_activo' => false,
                'configuracion' => [
                    'urls' => [
                        'https://www.sams.com.mx/browse/ofertas/3000001',
                    ],
                ],
            ]
        );

        Marketplace::updateOrCreate(
            ['slug' => 'bodega_aurrera'],
            [
                'nombre' => 'Bodega Aurrera',
                'url_busqueda' => 'https://www.bodegaaurrera.com.mx/inicio',
                'affiliate_id' => null,
                'app_id' => null,
                'es_activo' => false,
                'configuracion' => [
                    'urls' => [
                        'https://www.bodegaaurrera.com.mx/inicio',
                        'https://www.bodegaaurrera.com.mx/c/ftp/playstation-promociones-destacados',
                    ],
                ],
            ]
        );
    }
}
