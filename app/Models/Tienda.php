<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tienda extends Model
{
    protected $table = 'tiendas';

    protected $fillable = [
        'nombre',
        'clase_motor',
        'activo',
        'url_ofertas',
        'selector_css_principal',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }

    /**
     * Clases de motor disponibles para el select del Centro de Control.
     *
     * @return array<string, string>
     */
    public static function clasesMotorDisponibles(): array
    {
        return [
            \App\Motores\AliExpressMotor::class => 'AliExpress',
            \App\Motores\AmazonMexicoMotor::class => 'Amazon México',
            \App\Motores\BodegaAurreraMotor::class => 'Bodega Aurrera',
            \App\Motores\CalimaxMotor::class => 'Calimax',
            \App\Motores\ChedrauiMotor::class => 'Chedraui',
            \App\Motores\CoppelMotor::class => 'Coppel',
            \App\Motores\CostcoMotor::class => 'Costco',
            \App\Motores\ElektraMotor::class => 'Elektra',
            \App\Motores\LiverpoolMotor::class => 'Liverpool',
            \App\Motores\MercadoLibreMotor::class => 'Mercado Libre',
            \App\Motores\OfficeDepotMotor::class => 'Office Depot',
            \App\Motores\SamsClubMotor::class => 'Sams Club',
            \App\Motores\SorianaMotor::class => 'Soriana',
            \App\Motores\WalmartMotor::class => 'Walmart',
        ];
    }
}
