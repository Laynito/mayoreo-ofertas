<?php

namespace Database\Seeders;

use App\Fabrica\RastreadorFabrica;
use App\Models\Tienda;
use Illuminate\Database\Seeder;

/**
 * Pobla la tabla tiendas con las tiendas ya configuradas en el sistema (motores disponibles).
 * Así aparecen en Administración → Tiendas sin tener que crearlas a mano.
 */
class TiendaSeeder extends Seeder
{
    public function run(): void
    {
        $listado = RastreadorFabrica::listadoParaSeeder();
        foreach ($listado as $item) {
            Tienda::updateOrCreate(
                ['nombre' => $item['nombre']],
                [
                    'clase_motor' => $item['clase_motor'],
                    'activo' => true,
                    'url_ofertas' => null,
                    'selector_css_principal' => null,
                ]
            );
        }
    }
}
