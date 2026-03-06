<?php

namespace App\Filament\Widgets;

use App\Models\Click;
use App\Models\RedirectLink;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard: total clics hoy, enlace más cliqueado (24h), tasa de crecimiento respecto a ayer.
 */
class EstadisticasClicsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = null;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Estadísticas de clics';

    public function getStats(): array
    {
        $clicsHoy = Click::query()->whereDate('clicked_at', today())->count();
        $clicsAyer = Click::query()->whereDate('clicked_at', today()->subDay())->count();

        $tasaCrecimiento = null;
        if ($clicsAyer > 0) {
            $tasaCrecimiento = round((($clicsHoy - $clicsAyer) / $clicsAyer) * 100, 1);
        }

        $enlaceMasCliqueado = RedirectLink::query()
            ->withCount(['clics' => function (Builder $q): void {
                $q->where('clicked_at', '>=', now()->subDay());
            }])
            ->having('clics_count', '>', 0)
            ->orderByDesc('clics_count')
            ->first();

        $stats = [
            Stat::make('Clics hoy', $clicsHoy)
                ->description('Total de clics registrados hoy')
                ->color('primary'),
            Stat::make('Enlace más cliqueado (24h)', $enlaceMasCliqueado
                ? \Illuminate\Support\Str::limit($enlaceMasCliqueado->url_destino, 40)
                : '—')
                ->description($enlaceMasCliqueado ? $enlaceMasCliqueado->clics_count . ' clics' : 'Sin clics en las últimas 24h'),
        ];

        if ($tasaCrecimiento !== null) {
            $stats[] = Stat::make('Tasa de crecimiento', $tasaCrecimiento > 0 ? "+{$tasaCrecimiento}%" : "{$tasaCrecimiento}%")
                ->description('Respecto a ayer')
                ->color($tasaCrecimiento >= 0 ? 'success' : 'danger');
        } else {
            $stats[] = Stat::make('Tasa de crecimiento', '—')
                ->description('Ayer no hubo clics para comparar');
        }

        return $stats;
    }
}
