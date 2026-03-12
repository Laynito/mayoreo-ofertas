<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramPost;
use App\Models\Marketplace;
use App\Models\Producto;
use App\Services\AffiliateService;
use Illuminate\Console\Command;

class SyncProductosAffiliateCommand extends Command
{
    protected $signature = 'productos:sync-affiliate
                            {--send-telegram : Enviar a Telegram después de generar url_afiliado}';

    protected $description = 'Genera url_afiliado para productos que no lo tienen (ej. insertados por el scraper Python). Opcional: envía a Telegram (orden: ML, Coppel, otros por prioridad de marketplace).';

    public function handle(AffiliateService $affiliate): int
    {
        $query = Producto::query()
            ->where(function ($q) {
                $q->whereNull('url_afiliado')->orWhere('url_afiliado', '');
            })
            ->whereNotNull('url_producto');

        $count = $query->count();
        if ($count === 0) {
            $this->info('No hay productos sin url_afiliado.');

            return self::SUCCESS;
        }

        $this->info("Procesando {$count} producto(s)...");
        $sendTelegram = $this->option('send-telegram');

        $productos = $query->get();

        $prioridad = Marketplace::getPrioridadParaTelegram();
        $productos = $productos->sort(function ($a, $b) use ($affiliate, $prioridad) {
            $slugA = $affiliate->getSlugFromTienda($a->tienda, $a->url_producto);
            $slugB = $affiliate->getSlugFromTienda($b->tienda, $b->url_producto);
            $infoA = $prioridad[$slugA] ?? ['es_afiliados' => false, 'orden' => 999];
            $infoB = $prioridad[$slugB] ?? ['es_afiliados' => false, 'orden' => 999];
            if ($infoA['es_afiliados'] !== $infoB['es_afiliados']) {
                return $infoA['es_afiliados'] ? -1 : 1;
            }
            if ($infoA['orden'] !== $infoB['orden']) {
                return $infoA['orden'] <=> $infoB['orden'];
            }
            return $a->id <=> $b->id;
        })->values();

        // Evitar duplicados en Telegram: misma URL solo se envía una vez (se mantiene el de mayor prioridad)
        $productos = $productos->unique(function (Producto $p) {
            return trim((string) $p->url_producto);
        })->values();

        $telegramIndex = 0;
        foreach ($productos as $producto) {
            $producto->url_afiliado = $affiliate->getAffiliateLinkForProduct($producto->url_producto, $producto->tienda);
            $producto->saveQuietly();

            if ($sendTelegram) {
                ProcessTelegramPost::dispatch($producto)
                    ->onQueue('default')
                    ->delay(now()->addSeconds(8 * $telegramIndex));
                // Marcar esta URL en todas las filas (evita reenviar si hay duplicados por url_producto)
                Producto::query()
                    ->where('url_producto', trim((string) $producto->url_producto))
                    ->update(['last_sent_telegram_at' => now()]);
                $telegramIndex++;
            }
        }

        $this->info('Listo.');
        if ($sendTelegram) {
            $this->comment('Jobs de Telegram encolados (orden: marketplaces afiliados primero, luego ML, Coppel, otros). Ejecuta queue:work si está en cola.');
        }

        return self::SUCCESS;
    }
}
