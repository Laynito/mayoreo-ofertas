<?php

namespace App\Console\Commands;

use App\Models\Marketplace;
use App\Models\Producto;
use Illuminate\Console\Command;

class ProductosEstadoCommand extends Command
{
    protected $signature = 'productos:estado';

    protected $description = 'Muestra resumen de productos por tienda y cuántos faltan por enviar a Telegram (sin url_afiliado)';

    public function handle(): int
    {
        $this->info('=== Marketplaces (scrapers) ===');
        $marketplaces = Marketplace::query()->where('es_activo', true)->orderBy('slug')->get(['slug', 'nombre']);
        foreach ($marketplaces as $m) {
            $this->line("  [activo] {$m->slug} — {$m->nombre}");
        }
        if ($marketplaces->isEmpty()) {
            $this->warn('  No hay marketplaces activos.');
        }

        $this->newLine();
        $this->info('=== Productos por tienda ===');

        $porTienda = Producto::query()
            ->selectRaw('tienda, count(*) as total, sum(case when (url_afiliado is null or url_afiliado = "") then 1 else 0 end) as sin_afiliado, sum(case when last_sent_telegram_at is not null then 1 else 0 end) as enviados_telegram')
            ->groupBy('tienda')
            ->orderBy('tienda')
            ->get();

        if ($porTienda->isEmpty()) {
            $this->warn('  No hay productos en la base de datos.');
            $this->comment('  Ejecuta los scrapers (php artisan app:run-scraper o los .py a mano) para cargar ofertas.');
            return self::SUCCESS;
        }

        $this->table(
            ['Tienda', 'Total', 'Sin url_afiliado (pend. Telegram)', 'Ya enviados a Telegram'],
            $porTienda->map(fn ($r) => [
                $r->tienda,
                (string) $r->total,
                (string) $r->sin_afiliado,
                (string) $r->enviados_telegram,
            ])
        );

        $sinAfiliado = $porTienda->sum('sin_afiliado');
        if ($sinAfiliado > 0) {
            $this->comment("Para encolar y enviar esos {$sinAfiliado} producto(s) a Telegram: php artisan productos:sync-affiliate --send-telegram && php artisan queue:work --stop-when-empty");
        } else {
            $this->comment('Todos los productos ya tienen url_afiliado. Solo se enviarán a Telegram los que se agreguen en el próximo scrape.');
        }

        $coppel = $porTienda->firstWhere('tienda', 'Coppel');
        if ($coppel === null) {
            $this->newLine();
            $this->warn('  [Coppel] No hay ningún producto con tienda=Coppel en la BD.');
            $this->comment('  Posibles causas: marketplace Coppel desactivado en el panel, scraper_coppel.py no se ejecutó o falló (revisa logs), o la web de Coppel bloqueó la petición.');
        }

        return self::SUCCESS;
    }
}
