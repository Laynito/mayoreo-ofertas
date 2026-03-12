<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class MetricasAfiliadosPage extends Page
{
    protected static ?string $slug = 'metricas-afiliados';

    protected static ?string $navigationIcon = 'heroicon-o-cursor-arrow-rays';

    protected static ?string $navigationLabel = 'Clicks afiliados';

    protected static ?string $title = 'Métricas por clicks (afiliados)';

    protected static ?string $navigationGroup = 'Marketing';

    protected static string $view = 'filament.pages.metricas-afiliados-page';

    protected static ?int $navigationSort = 11;

    public ?array $porMarketplace = null;

    public ?array $ultimosClicks = null;

    public int $totalClicks = 0;

    public function mount(): void
    {
        $this->porMarketplace = DB::table('afiliado_clicks')
            ->select('marketplace_slug', DB::raw('count(*) as total'))
            ->groupBy('marketplace_slug')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'slug' => $r->marketplace_slug ?? '—',
                'total' => (int) $r->total,
                'nombre' => $this->nombreMarketplace($r->marketplace_slug),
            ])
            ->toArray();

        $this->totalClicks = (int) DB::table('afiliado_clicks')->count();

        $this->ultimosClicks = DB::table('afiliado_clicks')
            ->join('productos', 'afiliado_clicks.producto_id', '=', 'productos.id')
            ->select('afiliado_clicks.marketplace_slug', 'afiliado_clicks.created_at', 'productos.id as producto_id', 'productos.nombre')
            ->orderByDesc('afiliado_clicks.created_at')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'producto_id' => $r->producto_id,
                'nombre' => \Illuminate\Support\Str::limit($r->nombre, 50),
                'marketplace' => $this->nombreMarketplace($r->marketplace_slug),
                'created_at' => $r->created_at,
            ])
            ->toArray();
    }

    private function nombreMarketplace(?string $slug): string
    {
        return match ($slug) {
            'mercado_libre' => 'Mercado Libre',
            'coppel' => 'Coppel',
            'walmart' => 'Walmart',
            default => $slug ?? '—',
        };
    }
}
