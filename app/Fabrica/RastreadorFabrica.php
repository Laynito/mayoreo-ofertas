<?php

namespace App\Fabrica;

use App\Contratos\RastreadorTiendaInterface;
use App\Motores\AliExpressMotor;
use App\Motores\AmazonMexicoMotor;
use App\Motores\BodegaAurreraMotor;
use App\Motores\CalimaxMotor;
use App\Motores\ChedrauiMotor;
use App\Motores\CoppelMotor;
use App\Motores\CostcoMotor;
use App\Motores\ElektraMotor;
use App\Motores\LiverpoolMotor;
use App\Motores\MercadoLibreMotor;
use App\Motores\OfficeDepotMotor;
use App\Motores\SamsClubMotor;
use App\Motores\SorianaMotor;
use App\Motores\WalmartMotor;
use InvalidArgumentException;

/**
 * Fábrica que instancia el motor de rastreo según el nombre de la tienda.
 */
class RastreadorFabrica
{
    /**
     * Mapa de tienda (clave normalizada) a clase del motor.
     *
     * @var array<string, class-string<RastreadorTiendaInterface>>
     */
    protected static array $motores = [
        'walmart' => WalmartMotor::class,
        'amazon' => AmazonMexicoMotor::class,
        'mercado libre' => MercadoLibreMotor::class,
        'elektra' => ElektraMotor::class,
        'coppel' => CoppelMotor::class,
        'calimax' => CalimaxMotor::class,
        'liverpool' => LiverpoolMotor::class,
        'bodega aurrera' => BodegaAurreraMotor::class,
        'chedraui' => ChedrauiMotor::class,
        'soriana' => SorianaMotor::class,
        'costco' => CostcoMotor::class,
        'sams club' => SamsClubMotor::class,
        'aliexpress' => AliExpressMotor::class,
        'office depot' => OfficeDepotMotor::class,
    ];

    /**
     * Devuelve el motor de rastreo para la tienda indicada.
     *
     * @param  string  $tienda  Nombre de la tienda (ej. "Walmart", "Mercado Libre").
     * @return RastreadorTiendaInterface
     *
     * @throws InvalidArgumentException Si la tienda no tiene motor registrado.
     */
    public static function para(string $tienda): RastreadorTiendaInterface
    {
        $clave = self::normalizarClave($tienda);

        if (! isset(self::$motores[$clave])) {
            $disponibles = implode(', ', array_keys(self::$motores));

            throw new InvalidArgumentException(
                "No existe motor de rastreo para la tienda [{$tienda}]. Disponibles: {$disponibles}."
            );
        }

        $clase = self::$motores[$clave];

        return new $clase;
    }

    /**
     * Normaliza el nombre de la tienda para usarlo como clave (minúsculas, sin espacios extra).
     */
    protected static function normalizarClave(string $tienda): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $tienda)));
    }

    /**
     * Indica si existe un motor para la tienda dada.
     */
    public static function tieneMotorPara(string $tienda): bool
    {
        return isset(self::$motores[self::normalizarClave($tienda)]);
    }

    /**
     * Devuelve el nombre de tienda para guardar en BD (coincide con Filament/Producto).
     */
    public static function nombreParaBD(string $tienda): string
    {
        $clave = self::normalizarClave($tienda);
        $nombres = self::nombresParaBD();

        return $nombres[$clave] ?? mb_strtoupper(mb_substr($tienda, 0, 1)) . mb_strtolower(mb_substr(trim($tienda), 1));
    }

    /**
     * Lista de tiendas para menús y filtros (clave normalizada => nombre para BD).
     *
     * @return array<string, string>
     */
    public static function nombresParaBD(): array
    {
        return [
            'walmart' => 'Walmart',
            'amazon' => 'Amazon',
            'mercado libre' => 'Mercado Libre',
            'elektra' => 'Elektra',
            'coppel' => 'Coppel',
            'calimax' => 'Calimax',
            'liverpool' => 'Liverpool',
            'bodega aurrera' => 'Bodega Aurrera',
            'chedraui' => 'Chedraui',
            'soriana' => 'Soriana',
            'costco' => 'Costco',
            'sams club' => 'Sams Club',
            'aliexpress' => 'AliExpress',
            'office depot' => 'Office Depot',
        ];
    }

    /**
     * Nombres de tienda para pestañas y select (valor => etiqueta). Incluye "Otro" para productos manuales.
     *
     * @return array<string, string>
     */
    public static function tiendasParaMenu(): array
    {
        $lista = array_values(self::nombresParaBD());
        $opciones = array_combine($lista, $lista);
        $opciones['Otro'] = 'Otro';

        return $opciones ?: [];
    }

    /**
     * Nombre de tienda para BD a partir de la clase del motor (para EstadoMotorService).
     *
     * @param  class-string  $clase
     */
    public static function nombreTiendaDesdeClase(string $clase): ?string
    {
        $clave = array_search($clase, self::$motores, true);

        return $clave !== false ? (self::nombresParaBD()[$clave] ?? null) : null;
    }
}
