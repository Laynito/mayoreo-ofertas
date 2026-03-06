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

    /** Tokens OAuth Mercado Libre (guardados tras callback; usados por el motor para API). */
    public const CLAVE_ML_ACCESS_TOKEN = 'mercado_libre_access_token';
    public const CLAVE_ML_REFRESH_TOKEN = 'mercado_libre_refresh_token';
    public const CLAVE_ML_EXPIRES_AT = 'mercado_libre_expires_at';

    /** Configuración crítica (prioridad sobre .env; editables en Filament → Ajustes). */
    public const CLAVE_TELEGRAM_TOKEN = 'telegram_token';
    public const CLAVE_TELEGRAM_CHAT_ID = 'telegram_chat_id';
    public const CLAVE_ML_APP_ID = 'ml_app_id';
    public const CLAVE_ML_SECRET_KEY = 'ml_secret_key';
    public const CLAVE_ML_AFFILIATE_ID = 'ml_affiliate_id';
    public const CLAVE_AMAZON_TAG = 'amazon_tag';
    public const CLAVE_PROXY_HABILITADO = 'proxy_habilitado';
    public const CLAVE_PROXY_URL = 'proxy_url';
    /** Chat ID Premium (ofertas con % alto). Opcional; si está vacío solo se usa Chat ID Free. */
    public const CLAVE_TELEGRAM_CHAT_ID_PREMIUM = 'telegram_chat_id_premium';
    /** Admitad: base URL y publisher ID (prioridad sobre .env). */
    public const CLAVE_ADMITAD_BASE_URL = 'admitad_base_url';
    public const CLAVE_ADMITAD_PUBLISHER_ID = 'admitad_publisher_id';

    /** Claves que usan caché (para limpiar todas de golpe). */
    public const CLAVES_CACHE = [
        self::CLAVE_PORCENTAJE_MINIMO,
        self::CLAVE_REQUIERE_DESCUENTO_ADICIONAL,
        self::CLAVE_PORCENTAJE_PREMIUM,
        self::CLAVE_ENVIAR_IMAGENES,
        self::CLAVE_TELEGRAM_TOKEN,
        self::CLAVE_TELEGRAM_CHAT_ID,
        self::CLAVE_TELEGRAM_CHAT_ID_PREMIUM,
        self::CLAVE_ML_APP_ID,
        self::CLAVE_ML_SECRET_KEY,
        self::CLAVE_ML_AFFILIATE_ID,
        self::CLAVE_AMAZON_TAG,
        self::CLAVE_PROXY_HABILITADO,
        self::CLAVE_PROXY_URL,
        self::CLAVE_ADMITAD_BASE_URL,
        self::CLAVE_ADMITAD_PUBLISHER_ID,
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
        if (in_array($clave, [
            self::CLAVE_REQUIERE_DESCUENTO_ADICIONAL,
            self::CLAVE_ENVIAR_IMAGENES,
            self::CLAVE_PROXY_HABILITADO,
        ], true)) {
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

    /** Porcentaje mínimo de ahorro para enviar al canal Premium (≥ este % → Premium; 10–(N-1)% → Free). Default 40. */
    public static function porcentajeMinimoParaPremium(): int
    {
        $v = self::obtener(self::CLAVE_PORCENTAJE_PREMIUM, 40);

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

    /** Token del bot de Telegram (BD tiene prioridad sobre .env). */
    public static function getTelegramToken(): ?string
    {
        $v = self::obtener(self::CLAVE_TELEGRAM_TOKEN);
        return $v !== null && $v !== '' ? (string) $v : config('services.telegram.token');
    }

    /** Chat ID Free de Telegram (BD tiene prioridad sobre .env). */
    public static function getTelegramChatId(): ?string
    {
        $v = self::obtener(self::CLAVE_TELEGRAM_CHAT_ID);
        return $v !== null && $v !== '' ? (string) $v : config('services.telegram.chat_id');
    }

    /** Chat ID Premium de Telegram (solo ofertas con % ≥ porcentaje premium). Opcional. */
    public static function getTelegramChatIdPremium(): ?string
    {
        $v = self::obtener(self::CLAVE_TELEGRAM_CHAT_ID_PREMIUM);
        if ($v !== null && $v !== '') {
            return (string) $v;
        }
        $env = config('services.telegram.chat_id_premium');
        return $env !== null && $env !== '' ? (string) $env : null;
    }

    /** App ID de Mercado Libre (BD prioridad sobre .env). Clave: ML_APP_ID / services.mercado_libre.app_id. */
    public static function getMlAppId(): ?string
    {
        $v = self::obtener(self::CLAVE_ML_APP_ID);
        return $v !== null && $v !== '' ? (string) $v : config('services.mercado_libre.app_id');
    }

    /** Redirect URI OAuth Mercado Libre. Clave: ML_REDIRECT_URI / services.mercado_libre.redirect_uri. */
    public static function getMlRedirectUri(): ?string
    {
        $v = config('services.mercado_libre.redirect_uri');
        return $v !== null && $v !== '' ? (string) $v : null;
    }

    /** Secret Key de Mercado Libre (BD prioridad sobre .env). */
    public static function getMlSecretKey(): ?string
    {
        $v = self::obtener(self::CLAVE_ML_SECRET_KEY);
        return $v !== null && $v !== '' ? (string) $v : config('services.mercado_libre.secret_key');
    }

    /** ID de afiliado Mercado Libre (ej. 187001804). BD prioridad sobre .env. */
    public static function getMlAffiliateId(): ?string
    {
        $v = self::obtener(self::CLAVE_ML_AFFILIATE_ID);
        return $v !== null && $v !== '' ? (string) $v : config('services.mercado_libre.affiliate_id');
    }

    /** Tag de afiliado Amazon (ej. micosmtics-20). BD prioridad sobre .env. */
    public static function getAmazonTag(): ?string
    {
        $v = self::obtener(self::CLAVE_AMAZON_TAG);
        return $v !== null && $v !== '' ? (string) $v : config('services.amazon_tag');
    }

    /** Si el proxy global está habilitado (switch en Ajustes). Por defecto true si PROXY_URL está definido en .env. */
    public static function isProxyHabilitado(): bool
    {
        $v = self::obtener(self::CLAVE_PROXY_HABILITADO);
        if ($v !== null) {
            return (bool) $v;
        }
        $envUrl = config('services.proxy_url');
        return $envUrl !== null && $envUrl !== '';
    }

    /** URL del proxy global (solo si proxy está habilitado). Prioridad: BD (Centro de Control) → .env. */
    public static function getProxyUrl(): ?string
    {
        if (! self::isProxyHabilitado()) {
            return null;
        }
        $v = self::obtener(self::CLAVE_PROXY_URL);
        if ($v !== null && $v !== '') {
            return (string) $v;
        }
        $url = config('services.proxy_url');
        return $url !== null && $url !== '' ? $url : null;
    }

    /** Base URL Admitad (BD prioridad sobre .env). */
    public static function getAdmitadBaseUrl(): ?string
    {
        $v = self::obtener(self::CLAVE_ADMITAD_BASE_URL);
        return $v !== null && $v !== '' ? (string) $v : config('services.admitad.base_url');
    }

    /** Publisher ID Admitad (BD prioridad sobre .env). */
    public static function getAdmitadPublisherId(): ?string
    {
        $v = self::obtener(self::CLAVE_ADMITAD_PUBLISHER_ID);
        return $v !== null && $v !== '' ? (string) $v : (config('services.admitad.website_id') ?? config('services.admitad.id'));
    }
}
