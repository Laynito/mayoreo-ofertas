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
                $canonical = $affiliate->getCanonicalAffiliateLink($producto->url_producto);
                $old = $producto->url_afiliado;
                $isClickUrl = $old && (str_contains($old, 'click1.mercadolibre') || str_contains($old, 'mclics/clicks'));
                if ($old !== $canonical || $isClickUrl) {
                    if (! $dryRun) {
                        $producto->url_afiliado = $canonical;
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
            : "Listo. Se actualizaron {$updated} enlace(s) a la URL canónica (sin click1.mercadolibre).");

        return self::SUCCESS;
    }
}
