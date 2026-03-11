<?php

namespace App\Console\Commands;

use App\Services\AdmitadService;
use Illuminate\Console\Command;

class AdmitadProgramsCommand extends Command
{
    protected $signature = 'admitad:programs
                            {--limit=20 : Número de programas a listar}
                            {--offset=0 : Desplazamiento}
                            {--language= : Filtrar por idioma (ej. es)}
                            {--website= : ID del espacio publicitario para filtrar}';

    protected $description = 'Lista programas de afiliados (advcampaigns) desde la API de Admitad';

    public function handle(AdmitadService $admitad): int
    {
        if (! config('services.admitad.client_id') || ! config('services.admitad.client_secret')) {
            $this->error('Configura ADMITAD_CLIENT_ID y ADMITAD_CLIENT_SECRET en .env');
            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $language = $this->option('language') ?: null;
        $website = $this->option('website') ? (int) $this->option('website') : null;

        $this->info("Obteniendo programas (limit={$limit}, offset={$offset})...");

        $data = $admitad->getPrograms($limit, $offset, $website, $language);
        $results = $data['results'] ?? [];
        $meta = $data['_meta'] ?? [];

        if (empty($results)) {
            $this->warn('No se encontraron programas o la API no devolvió datos.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($results as $p) {
            $rows[] = [
                $p['id'] ?? '-',
                $p['name'] ?? '-',
                $p['site_url'] ?? '-',
                $p['status'] ?? '-',
                $p['currency'] ?? '-',
                ($p['connected'] ?? false) ? 'Sí' : 'No',
            ];
        }

        $this->table(['ID', 'Nombre', 'URL', 'Estado', 'Moneda', 'Conectado'], $rows);
        $this->line('Total: ' . ($meta['count'] ?? count($results)) . ' (limit ' . ($meta['limit'] ?? $limit) . ', offset ' . ($meta['offset'] ?? $offset) . ')');

        return self::SUCCESS;
    }
}
