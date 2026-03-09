<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramPost;
use App\Models\Producto;
use App\Services\AffiliateService;
use Illuminate\Console\Command;

class SyncProductosAffiliateCommand extends Command
{
    protected $signature = 'productos:sync-affiliate
                            {--send-telegram : Enviar a Telegram después de generar url_afiliado}';

    protected $description = 'Genera url_afiliado para productos que no lo tienen (ej. insertados por el scraper Python). Opcional: envía a Telegram.';

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

        $telegramIndex = 0;
        $query->chunkById(50, function ($productos) use ($affiliate, $sendTelegram, &$telegramIndex) {
            foreach ($productos as $producto) {
                $producto->url_afiliado = $affiliate->convertToAffiliateLink($producto->url_producto);
                $producto->saveQuietly();

                if ($sendTelegram) {
                    ProcessTelegramPost::dispatch($producto)
                        ->delay(now()->addSeconds(5 * $telegramIndex));
                    $telegramIndex++;
                }
            }
        });

        $this->info('Listo.');
        if ($sendTelegram) {
            $this->comment('Jobs de Telegram encolados. Ejecuta queue:work si está en cola.');
        }

        return self::SUCCESS;
    }
}
