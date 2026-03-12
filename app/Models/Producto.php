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
        'last_published_at',
        'last_sent_telegram_at',
    ];

    protected $casts = [
        'precio_actual' => 'decimal:2',
        'precio_original' => 'decimal:2',
        'descuento' => 'integer',
        'last_published_at' => 'datetime',
        'last_sent_telegram_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Producto $producto): void {
            if (empty($producto->url_afiliado) && ! empty($producto->url_producto)) {
                $producto->url_afiliado = app(AffiliateService::class)
                    ->getAffiliateLinkForProduct($producto->url_producto, $producto->tienda);
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

        // Si el SKU ya existía y el precio bajó, notificar a Telegram (nuevo descuento)
        static::updated(function (Producto $producto): void {
            if (! $producto->wasChanged('precio_actual')) {
                return;
            }
            $precioAnterior = (float) $producto->getOriginal('precio_actual');
            $precioNuevo = (float) $producto->precio_actual;
            if ($precioAnterior <= 0 || $precioNuevo >= $precioAnterior) {
                return;
            }
            $nextAt = Carbon::parse(Cache::get('telegram_next_run_at', now()));
            if ($nextAt->isPast()) {
                $nextAt = now();
            }
            ProcessTelegramPost::dispatch($producto)->delay($nextAt);
            Cache::put('telegram_next_run_at', $nextAt->copy()->addSeconds(5), 3600);
        });
    }
}
