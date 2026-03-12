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
     * Obtiene el marketplace de Facebook (slug facebook) activo.
     */
    public static function facebookActivo(): ?self
    {
        return static::query()
            ->where('slug', 'facebook')
            ->where('es_activo', true)
            ->first();
    }

    /**
     * Obtiene el marketplace de TikTok (slug tiktok).
     */
    public static function tiktokActivo(): ?self
    {
        return static::query()
            ->where('slug', 'tiktok')
            ->where('es_activo', true)
            ->first();
    }

    /**
     * Descripción de perfil para TikTok (máx. 80 caracteres). Para usar en TikTok Development / bio.
     */
    public function getTiktokBioDescription(): string
    {
        $config = $this->configuracion ?? [];
        $bio = (string) ($config['bio_description'] ?? '');

        return \Illuminate\Support\Str::limit($bio, 80, '');
    }

    /**
     * Descripción de la app para TikTok for Developers (máx. 120 caracteres). Se muestra a los usuarios.
     */
    public function getTiktokAppDescription(): string
    {
        $config = $this->configuracion ?? [];
        $desc = (string) ($config['app_description'] ?? '');

        return \Illuminate\Support\Str::limit($desc, 120, '');
    }

    /**
     * URL de términos de uso (Terms of Service) para TikTok for Developers.
     */
    public function getTiktokTermsUrl(): string
    {
        $config = $this->configuracion ?? [];
        $url = (string) ($config['terms_of_service_url'] ?? '');

        return $url !== '' ? $url : url('/terminos');
    }

    /**
     * URL de aviso de privacidad (Privacy Policy) para TikTok for Developers.
     */
    public function getTiktokPrivacyUrl(): string
    {
        $config = $this->configuracion ?? [];
        $url = (string) ($config['privacy_policy_url'] ?? '');

        return $url !== '' ? $url : url('/aviso-de-privacidad');
    }

    /**
     * Client Key de la app de TikTok (desde configuracion o env).
     */
    public function getTiktokClientKey(): string
    {
        $config = $this->configuracion ?? [];
        if (filled($config['client_key'] ?? null)) {
            return (string) $config['client_key'];
        }

        return (string) config('services.tiktok.client_key', '');
    }

    /**
     * Client Secret de la app de TikTok (desde configuracion o env).
     */
    public function getTiktokClientSecret(): string
    {
        $config = $this->configuracion ?? [];
        if (filled($config['client_secret'] ?? null)) {
            return (string) $config['client_secret'];
        }

        return (string) config('services.tiktok.client_secret', '');
    }

    /**
     * Indica si este marketplace está marcado como programa de afiliados (prioridad al enviar a Telegram).
     */
    public function esAfiliados(): bool
    {
        $config = $this->configuracion ?? [];

        return (bool) ($config['es_afiliados'] ?? false);
    }

    /**
     * Mapa de prioridad para envío a Telegram: slug => ['es_afiliados' => bool, 'orden' => int].
     * Orden: mercado_libre=1, coppel=2, walmart=3, resto por orden de registro.
     * Se usa para ordenar productos (afiliados primero, luego por este orden).
     *
     * @return array<string, array{es_afiliados: bool, orden: int}>
     */
    public static function getPrioridadParaTelegram(): array
    {
        $ordenFijo = [
            'mercado_libre' => 1,
            'coppel' => 2,
            'walmart' => 3,
            'elektra' => 4,
        ];
        $marketplaces = static::query()
            ->where('es_activo', true)
            ->orderByRaw("CASE slug WHEN 'mercado_libre' THEN 1 WHEN 'coppel' THEN 2 WHEN 'walmart' THEN 3 WHEN 'elektra' THEN 4 ELSE 5 END")
            ->orderBy('id')
            ->get();
        $map = [];
        $orden = 5; // slugs no listados en ordenFijo (ej. facebook, tiktok)
        foreach ($marketplaces as $m) {
            $slug = $m->slug;
            $map[$slug] = [
                'es_afiliados' => $m->esAfiliados(),
                'orden' => $ordenFijo[$slug] ?? $orden,
            ];
            if (! isset($ordenFijo[$slug])) {
                $orden++;
            }
        }

        return $map;
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
