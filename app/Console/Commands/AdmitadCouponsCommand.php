<?php

namespace App\Console\Commands;

use App\Services\AdmitadService;
use Illuminate\Console\Command;

class AdmitadCouponsCommand extends Command
{
    protected $signature = 'admitad:coupons
                            {--limit=20 : Número de cupones}
                            {--offset=0 : Desplazamiento}
                            {--region=MX : Región (ej. MX, US)}
                            {--language=es : Idioma}
                            {--campaign= : ID del programa para filtrar}
                            {--search= : Buscar por nombre/descripción}';

    protected $description = 'Lista cupones del publisher desde la API de Admitad';

    public function handle(AdmitadService $admitad): int
    {
        if (! config('services.admitad.client_id') || ! config('services.admitad.client_secret')) {
            $this->error('Configura ADMITAD_CLIENT_ID y ADMITAD_CLIENT_SECRET en .env');
            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $region = $this->option('region') ?: 'MX';
        $language = $this->option('language') ?: 'es';
        $campaign = $this->option('campaign') ? (int) $this->option('campaign') : null;
        $search = $this->option('search') ?: null;

        $this->info("Obteniendo cupones (region={$region}, limit={$limit})...");

        $data = $admitad->getCoupons($limit, $offset, $region, $language, $campaign, $search);
        $results = $data['results'] ?? [];
        $meta = $data['_meta'] ?? [];

        if (empty($results)) {
            $this->warn('No se encontraron cupones o la API no devolvió datos. Comprueba el scope "coupons" en ADMITAD_SCOPE.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($results as $c) {
            $campaignName = $c['campaign']['name'] ?? '-';
            $rows[] = [
                $c['id'] ?? '-',
                $c['name'] ?? '-',
                $c['short_name'] ?? '-',
                $campaignName,
                $c['discount'] ?? '-',
                $c['date_end'] ?? '-',
            ];
        }

        $this->table(['ID', 'Nombre', 'Código corto', 'Programa', 'Descuento', 'Válido hasta'], $rows);
        $this->line('Total: ' . ($meta['count'] ?? count($results)));

        return self::SUCCESS;
    }
}
