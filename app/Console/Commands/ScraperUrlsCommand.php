<?php

namespace App\Console\Commands;

use App\Models\Marketplace;
use Illuminate\Console\Command;

class ScraperUrlsCommand extends Command
{
    protected $signature = 'scraper:urls
                            {--marketplace= : Solo este slug (mercado_libre, walmart, coppel, elektra, sams_club, bodega_aurrera)}';

    protected $description = 'Muestra qué URLs usará cada scraper (desde panel Marketplaces). Sirve para verificar que las URLs de secciones funcionan.';

    public function handle(): int
    {
        $solo = $this->option('marketplace');

        $this->info('URLs que usan los scrapers (desde BD → marketplaces.configuracion.urls o url_busqueda):');
        $this->newLine();

        foreach (['mercado_libre', 'walmart', 'coppel', 'elektra', 'sams_club', 'bodega_aurrera'] as $slug) {
            if ($solo !== null && $solo !== $slug) {
                continue;
            }

            $m = Marketplace::query()->where('slug', $slug)->first();
            $urls = $m ? $m->getUrlsSecciones() : [];
            $urlBusqueda = $m ? trim((string) $m->url_busqueda) : '';
            $activo = $m ? $m->es_activo : false;

            $label = match($slug) {
                'mercado_libre'   => 'Mercado Libre',
                'walmart'         => 'Walmart',
                'coppel'          => 'Coppel',
                'elektra'         => 'Elektra',
                'sams_club'       => "Sam's Club",
                'bodega_aurrera'  => 'Bodega Aurrera',
                default           => $slug,
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
        $this->line('O manualmente (usar el Python del venv, NO "python3 venv/bin/python"):');
        $this->line('  python/venv/bin/python python/scraper_ml.py                 # Mercado Libre');
        $this->line('  python/venv/bin/python python/walmart_sitemap_scraper.py   # Walmart (sitemap, sin navegador)');
        $this->line('  python/venv/bin/python python/scraper_coppel.py           # Coppel');
        $this->line('  python/venv/bin/python python/scraper_elektra.py           # Elektra');
        $this->line('  python/venv/bin/python python/scraper_sams.py               # Sam\'s Club');
        $this->line('  python/venv/bin/python python/scraper_bodega_aurrera.py    # Bodega Aurrera');

        return self::SUCCESS;
    }
}
