<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Configuracion extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'configuracion';

    protected $fillable = ['clave', 'valor'];

    public const CACHE_TAG = 'configuracion';

    /** Clave de caché global para notificaciones (se limpia en cada guardado para cambios instantáneos). */
    public const CACHE_KEY_NOTIFICACIONES = 'configuracion_notificaciones';

    public const CLAVE_PORCENTAJE_MINIMO = 'notificaciones_porcentaje_minimo';

    public const CLAVE_REQUIERE_DESCUENTO_ADICIONAL = 'notificaciones_requiere_descuento_adicional';

    public const CLAVE_PORCENTAJE_PREMIUM = 'notificaciones_porcentaje_premium';

    public const CLAVE_ENVIAR_IMAGENES = 'enviar_imagenes';

    /** Claves que usan caché (para limpiar todas de golpe). */
    public const CLAVES_CACHE = [
        self::CLAVE_PORCENTAJE_MINIMO,
        self::CLAVE_REQUIERE_DESCUENTO_ADICIONAL,
        self::CLAVE_PORCENTAJE_PREMIUM,
        self::CLAVE_ENVIAR_IMAGENES,
    ];

    protected static function booted(): void
    {
        static::saved(function (): void {
            Cache::forget(self::CACHE_KEY_NOTIFICACIONES);
        });
    }

    /**
     * Obtiene un valor de configuración (con cache corto para no golpear DB en cada job).
     */
    public static function obtener(string $clave, mixed $porDefecto = null): mixed
    {
        $cacheKey = self::CACHE_TAG . '.' . $clave;

        return Cache::remember($cacheKey, 300, function () use ($clave, $porDefecto) {
            $row = self::query()->where('clave', $clave)->first();

            return $row !== null ? self::castValor($clave, $row->valor) : $porDefecto;
        });
    }

    /**
     * Guarda un valor y limpia la cache para esa clave.
     */
    public static function guardar(string $clave, mixed $valor): void
    {
        $valorGuardar = is_bool($valor) ? ($valor ? '1' : '0') : (string) $valor;
        self::query()->updateOrInsert(
            ['clave' => $clave],
            ['valor' => $valorGuardar]
        );
        Cache::forget(self::CACHE_TAG . '.' . $clave);
        Cache::forget(self::CACHE_KEY_NOTIFICACIONES);
    }

    protected static function castValor(string $clave, ?string $valor): mixed
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if ($clave === self::CLAVE_REQUIERE_DESCUENTO_ADICIONAL || $clave === self::CLAVE_ENVIAR_IMAGENES) {
            return in_array(strtolower($valor), ['1', 'true', 'yes', 'on'], true);
        }
        if ($clave === self::CLAVE_PORCENTAJE_MINIMO || $clave === self::CLAVE_PORCENTAJE_PREMIUM) {
            return (int) $valor;
        }

        return $valor;
    }

    /** Porcentaje mínimo de ahorro para notificar por Telegram (default 10). */
    public static function porcentajeMinimoNotificacion(): int
    {
        $v = self::obtener(self::CLAVE_PORCENTAJE_MINIMO, 10);

        return (int) $v;
    }

    /** Si solo se notifican productos con permite_descuento_adicional = true (default true). */
    public static function requiereDescuentoAdicional(): bool
    {
        $v = self::obtener(self::CLAVE_REQUIERE_DESCUENTO_ADICIONAL, true);

        return (bool) $v;
    }

    /** Porcentaje mínimo de ahorro para enviar al canal Premium (≥ este % → Premium; 10–(N-1)% → Free). Default 20. */
    public static function porcentajeMinimoParaPremium(): int
    {
        $v = self::obtener(self::CLAVE_PORCENTAJE_PREMIUM, 20);

        return (int) $v;
    }

    /** Si se envían ofertas con imagen (sendPhoto). Si está desactivado, se usa solo texto. Default true. */
    public static function enviarImagenes(): bool
    {
        $v = self::obtener(self::CLAVE_ENVIAR_IMAGENES, true);

        return (bool) $v;
    }

    /** Limpia la caché de toda la configuración (llamar tras guardar desde el dashboard). */
    public static function limpiarCache(): void
    {
        foreach (self::CLAVES_CACHE as $clave) {
            Cache::forget(self::CACHE_TAG . '.' . $clave);
        }
        Cache::forget(self::CACHE_KEY_NOTIFICACIONES);
    }
}
