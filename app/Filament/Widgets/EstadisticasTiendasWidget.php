<?php

namespace App\Filament\Widgets;

use App\Models\Producto;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Widget del escritorio: resumen de productos por tienda_origen y total de Súper Ofertas (ahorro > 50%).
 */
class EstadisticasTiendasWidget extends BaseWidget
{
    protected static ?string $pollingInterval = null;

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 0;

    protected ?string $heading = 'Estadísticas por tienda';

    public function getStats(): array
    {
        $superOfertas = Producto::query()->where('porcentaje_ahorro', '>', 50)->count();
        $totalProductos = Producto::query()->count();

        $stats = [
            Stat::make('Súper Ofertas (ahorro > 50%)', $superOfertas)
                ->description('Productos con descuento mayor al 50%')
                ->color('success'),
            Stat::make('Total productos', $totalProductos)
                ->description('En todas las tiendas'),
        ];

        // Resumen por tienda_origen
        $porTienda = Producto::query()
            ->select('tienda_origen', DB::raw('count(*) as total'))
            ->groupBy('tienda_origen')
            ->orderByDesc('total')
            ->get();

        foreach ($porTienda as $fila) {
            $stats[] = Stat::make($fila->tienda_origen, $fila->total)
                ->description('productos');
        }

        return $stats;
    }
}
