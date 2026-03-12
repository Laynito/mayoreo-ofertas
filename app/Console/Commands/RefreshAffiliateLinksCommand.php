<?php

namespace App\Console\Commands;

use App\Models\Producto;
use App\Services\AffiliateService;
use Illuminate\Console\Command;

class RefreshAffiliateLinksCommand extends Command
{
    protected $signature = 'app:refresh-affiliate-links
                            {--dry-run : Solo mostrar qué se actualizaría, sin guardar}';

    protected $description = 'Reemplaza todas las url_afiliado por el enlace canónico (producto directo + params). Elimina URLs de click1.mercadolibre que redirigen al inicio.';

    public function handle(AffiliateService $affiliate): int
    {
        $dryRun = $this->option('dry-run');
        $query = Producto::query()->whereNotNull('url_producto')->where('url_producto', '!=', '');

        $total = $query->count();
        if ($total === 0) {
            $this->info('No hay productos con url_producto.');
            return self::SUCCESS;
        }

        $this->info("Productos a actualizar: {$total}" . ($dryRun ? ' (dry-run, no se guardará)' : ''));
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $query->chunkById(100, function ($productos) use ($affiliate, $dryRun, &$updated, $bar) {
            foreach ($productos as $producto) {
                $correctLink = $affiliate->getAffiliateLinkForProduct($producto->url_producto, $producto->tienda);
                $old = $producto->url_afiliado;
                $isClickUrl = $old && (str_contains((string) $old, 'click1.mercadolibre') || str_contains((string) $old, 'mclics/clicks'));
                if ($old !== $correctLink || $isClickUrl) {
                    if (! $dryRun) {
                        $producto->url_afiliado = $correctLink;
                        $producto->saveQuietly();
                    }
                    $updated++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info($dryRun
            ? "Se actualizarían {$updated} enlace(s). Ejecuta sin --dry-run para aplicar."
            : "Listo. Se actualizaron {$updated} enlace(s) (ML=afiliado ML, Coppel/otros=su código).");

        return self::SUCCESS;
    }
}
