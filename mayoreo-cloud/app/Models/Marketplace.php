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
        'affiliate_user',
        'affiliate_password',
        'session_data',
        'email',
        'password',
        'cookies_json',
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
     * Obtiene el marketplace de Walmart (slug walmart) activo.
     */
    public static function walmartActivo(): ?self
    {
        return static::query()
            ->where('slug', 'walmart')
            ->where('es_activo', true)
            ->first();
    }

    /**
     * Obtiene el marketplace de Coppel (slug coppel) activo.
     */
    public static function coppelActivo(): ?self
    {
        return static::query()
            ->where('slug', 'coppel')
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

    /**
     * URLs de secciones (ofertas) guardadas en configuracion.urls.
     * Si hay al menos una, el scraper las usa en lugar de la URL única (url_busqueda).
     *
     * @return array<int, string>
     */
    public function getUrlsSecciones(): array
    {
        $config = $this->configuracion ?? [];
        $urls = $config['urls'] ?? [];
        if (! is_array($urls)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $urls)));
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
