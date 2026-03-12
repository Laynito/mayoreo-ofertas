<?php

namespace App\Jobs;

use App\Models\Producto;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SendProductToTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Evitar duplicados: mismo producto (URL canónica) no se envía dos veces en este tiempo (segundos). */
    private const DEDUP_TTL_SECONDS = 600;

    public function __construct(
        public Producto $producto
    ) {}

    /** URL canónica para deduplicar: sin query string ni fragmento (evita 3 envíos por misma oferta con ?ref=...). */
    private static function urlCanonical(string $url): string
    {
        $u = trim($url);
        if ($u === '') {
            return '';
        }
        $parsed = parse_url($u);
        $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : 'https';
        $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        $path = rtrim($path, '/') ?: '/';
        return $scheme . '://' . $host . $path;
    }

    public function handle(TelegramService $telegram): void
    {
        $url = trim((string) $this->producto->url_producto);
        $canonical = self::urlCanonical($url);
        if ($canonical === '') {
            return;
        }
        $cacheKey = 'telegram_sent_' . md5($canonical);
        if (Cache::has($cacheKey)) {
            return;
        }
        $ok = $telegram->sendOffer($this->producto);
        if ($ok) {
            Cache::put($cacheKey, true, self::DEDUP_TTL_SECONDS);
            // Marcar como enviado este producto y cualquier otro con la misma URL canónica (evita reenviar duplicados)
            Producto::query()
                ->whereRaw('TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(COALESCE(url_producto,""), "?", 1), "#", 1)) = ?', [$canonical])
                ->update(['last_sent_telegram_at' => now()]);
        }
    }
}
