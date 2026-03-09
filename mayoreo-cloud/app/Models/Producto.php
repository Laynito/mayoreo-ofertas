<?php

namespace App\Models;

use App\Jobs\ProcessTelegramPost;
use App\Services\AffiliateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class Producto extends Model
{
    protected $fillable = [
        'nombre',
        'sku',
        'precio_actual',
        'precio_original',
        'descuento',
        'url_producto',
        'url_afiliado',
        'url_imagen',
        'tienda',
    ];

    protected $casts = [
        'precio_actual' => 'decimal:2',
        'precio_original' => 'decimal:2',
        'descuento' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Producto $producto): void {
            if (empty($producto->url_afiliado) && ! empty($producto->url_producto)) {
                $producto->url_afiliado = app(AffiliateService::class)
                    ->getCanonicalAffiliateLink($producto->url_producto);
            }
        });

        static::created(function (Producto $producto): void {
            $nextAt = Carbon::parse(Cache::get('telegram_next_run_at', now()));
            if ($nextAt->isPast()) {
                $nextAt = now();
            }
            ProcessTelegramPost::dispatch($producto)->delay($nextAt);
            Cache::put('telegram_next_run_at', $nextAt->copy()->addSeconds(5), 3600);
        });
    }
}
