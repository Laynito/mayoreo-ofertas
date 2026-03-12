<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramPost;
use App\Models\Marketplace;
use App\Models\Producto;
use App\Services\AffiliateService;
use Illuminate\Console\Command;

class TelegramSendVariedCommand extends Command
{
    protected $signature = 'telegram:send-varied
                            {--limit=5 : Máximo de ofertas a encolar por ejecución}
                            {--hours=12 : No reenviar un producto si se envió hace menos de esta cantidad de horas}';

    protected $description = 'Envía ofertas variadas a Telegram (productos que ya tienen url_afiliado y no se enviaron recientemente). Orden: ML, Coppel, otros.';

    public function handle(AffiliateService $affiliate): int
    {
        $limit = (int) $this->option('limit');
        $hours = (int) $this->option('hours');
        $since = now()->subHours($hours);

        $query = Producto::query()
            ->whereNotNull('url_producto')
            ->where(function ($q) {
                $q->whereNotNull('url_afiliado')->where('url_afiliado', '!=', '');
            })
            ->where(function ($q) use ($since) {
                $q->whereNull('last_sent_telegram_at')->orWhere('last_sent_telegram_at', '<', $since);
            });

        $productos = $query->get();
        if ($productos->isEmpty()) {
            $this->info('No hay productos elegibles (todos enviados recientemente o sin url_afiliado).');

            return self::SUCCESS;
        }

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

        $productos = $productos->unique(fn (Producto $p) => trim((string) $p->url_producto))->values()->take($limit);

        $telegramIndex = 0;
        $now = now();
        foreach ($productos as $producto) {
            ProcessTelegramPost::dispatch($producto)
                ->onQueue('default')
                ->delay(now()->addSeconds(8 * $telegramIndex));
            // Marcar esta URL en todas las filas (evita reenviar si hay duplicados por url_producto)
            Producto::query()
                ->where('url_producto', trim((string) $producto->url_producto))
                ->update(['last_sent_telegram_at' => $now]);
            $telegramIndex++;
        }

        $this->info("Encoladas {$telegramIndex} oferta(s) para Telegram.");

        return self::SUCCESS;
    }
}
