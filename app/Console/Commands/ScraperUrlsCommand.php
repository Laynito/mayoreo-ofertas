<?php

namespace App\Console\Commands;

use App\Models\Marketplace;
use Illuminate\Console\Command;

class ScraperUrlsCommand extends Command
{
    protected $signature = 'scraper:urls
                            {--marketplace= : Solo este slug (mercado_libre, walmart, coppel, elektra)}';

    protected $description = 'Muestra qué URLs usará cada scraper (desde panel Marketplaces). Sirve para verificar que las URLs de secciones funcionan.';

    public function handle(): int
    {
        $solo = $this->option('marketplace');

        $this->info('URLs que usan los scrapers (desde BD → marketplaces.configuracion.urls o url_busqueda):');
        $this->newLine();

        foreach (['mercado_libre', 'walmart', 'coppel', 'elektra'] as $slug) {
            if ($solo !== null && $solo !== $slug) {
                continue;
            }

            $m = Marketplace::query()->where('slug', $slug)->first();
            $urls = $m ? $m->getUrlsSecciones() : [];
            $urlBusqueda = $m ? trim((string) $m->url_busqueda) : '';
            $activo = $m ? $m->es_activo : false;

            $label = match($slug) {
                'mercado_libre' => 'Mercado Libre',
                'walmart'       => 'Walmart',
                'coppel'        => 'Coppel',
                'elektra'       => 'Elektra',
                default         => $slug,
            };
            $this->line("<fg=cyan>--- {$label} (slug: {$slug}) ---</>");
            $this->line('Activo: ' . ($activo ? 'Sí' : 'No'));

            if ($m === null) {
                $this->warn('  No hay marketplace con este slug en la BD.');
                $this->newLine();
                continue;
            }

            if ($urls !== []) {
                $this->line('Origen: <fg=green>URLs de secciones (ofertas)</> del panel');
                foreach ($urls as $i => $u) {
                    $this->line('  ' . ($i + 1) . '. ' . $u);
                }
            } else {
                if ($urlBusqueda !== '') {
                    $this->line('Origen: <fg=yellow>URL base de búsqueda / ofertas</>');
                    $this->line('  1. ' . $urlBusqueda);
                } else {
                    $this->line('Origen: <fg=gray>Por defecto</> (script usará sus URLs hardcodeadas)');
                }
            }

            $this->newLine();
        }

        $this->line('Para ejecutar scrapers:');
        $this->line('  php artisan app:run-scraper');
        $this->line('O manualmente:');
        $this->line('  python3 python/scraper_ml.py      # Mercado Libre');
        $this->line('  python3 python/walmart_sitemap_scraper.py   # Walmart (sitemap)');
        $this->line('  python3 python/scraper_elektra.py # Elektra (liquidación)');

        return self::SUCCESS;
    }
}
