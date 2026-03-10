<?php

namespace App\Console\Commands;

use App\Models\Marketplace;
use Illuminate\Console\Command;

class MarketplacesUrlsSeccionesCommand extends Command
{
    protected $signature = 'marketplaces:urls-secciones';

    protected $description = 'Muestra cuántas URLs de secciones (ofertas) tiene cada marketplace en la BD';

    public function handle(): int
    {
        $marketplaces = Marketplace::query()->orderBy('nombre')->get();

        if ($marketplaces->isEmpty()) {
            $this->warn('No hay marketplaces en la BD.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($marketplaces as $m) {
            $urls = $m->getUrlsSecciones();
            $rows[] = [
                $m->nombre,
                $m->slug,
                count($urls),
                implode(', ', array_slice($urls, 0, 3)) . (count($urls) > 3 ? '…' : ''),
            ];
        }

        $this->table(
            ['Marketplace', 'Slug', 'URLs', 'Primeras URLs'],
            $rows
        );

        return self::SUCCESS;
    }
}
