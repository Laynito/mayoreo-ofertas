<?php

namespace App\Console\Commands;

use App\Services\FacebookService;
use Illuminate\Console\Command;

class FacebookInsightsCommand extends Command
{
    protected $signature = 'facebook:insights
                            {--days=7 : Días hacia atrás}
                            {--metric=page_impressions,page_engaged_users : Métricas separadas por coma}';

    protected $description = 'Muestra insights de la Fan Page de Facebook (alcance, impresiones). Requiere 100+ me gusta en la página.';

    public function handle(FacebookService $facebook): int
    {
        if (! $facebook->hasCredentials()) {
            $this->error('Configura FB_PAGE_ID y FB_PAGE_ACCESS_TOKEN en .env o en el panel Marketplace → Facebook.');

            return self::FAILURE;
        }

        $pageInfo = $facebook->getPageInfo();
        if (isset($pageInfo['error'])) {
            $this->error('Error al obtener la página: ' . ($pageInfo['error']['message'] ?? json_encode($pageInfo['error'])));

            return self::FAILURE;
        }

        $this->info('Página: ' . ($pageInfo['name'] ?? $pageInfo['id'] ?? '—'));

        $days = (int) $this->option('days');
        $since = now()->subDays($days)->format('Y-m-d');
        $until = now()->format('Y-m-d');
        $metrics = array_map('trim', explode(',', $this->option('metric')));

        $result = $facebook->getInsights($metrics, 'day', $since, $until);

        if (isset($result['error'])) {
            $this->warn('Insights no disponibles: ' . ($result['error']['message'] ?? json_encode($result['error'])));
            $this->line('(La página necesita 100+ me gusta y token con permiso read_insights.)');

            return self::SUCCESS;
        }

        $data = $result['data'] ?? [];
        if (empty($data)) {
            $this->line('Sin datos de insights en el período.');

            return self::SUCCESS;
        }

        foreach ($data as $metricBlock) {
            $name = $metricBlock['name'] ?? 'metric';
            $values = $metricBlock['values'] ?? [];
            $this->line('');
            $this->line("<fg=cyan>{$name}</>");
            foreach ($values as $v) {
                $end = $v['end_time'] ?? '';
                $val = $v['value'] ?? 0;
                $this->line("  {$end} => {$val}");
            }
        }

        return self::SUCCESS;
    }
}
