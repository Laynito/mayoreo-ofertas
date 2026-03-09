<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Marketplace extends Model
{
    public const AFFILIATE_CONFIG_CACHE_KEY = 'affiliate_config.mercado_libre';

    protected $fillable = [
        'nombre',
        'slug',
        'url_busqueda',
        'affiliate_id',
        'app_id',
        'es_activo',
        'configuracion',
        'verification_code',
    ];

    protected $casts = [
        'es_activo' => 'boolean',
        'configuracion' => 'array',
    ];

    /**
     * Obtiene el marketplace de Mercado Libre (slug mercado_libre) activo.
     */
    public static function mercadoLibreActivo(): ?self
    {
        return static::query()
            ->where('slug', 'mercado_libre')
            ->where('es_activo', true)
            ->first();
    }

    /**
     * Obtiene el valor de matt_word desde configuracion.
     */
    public function getMattWord(): string
    {
        $config = $this->configuracion ?? [];
        return $config['matt_word'] ?? 'mayoreo_cloud';
    }

    protected static function booted(): void
    {
        static::saved(function (Marketplace $marketplace): void {
            if ($marketplace->slug === 'mercado_libre') {
                Cache::forget(self::AFFILIATE_CONFIG_CACHE_KEY);
            }
        });
    }
}
