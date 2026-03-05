<?php

namespace App\Filament\Resources\ProductoResource\Pages;

use App\Fabrica\RastreadorFabrica;
use App\Filament\Resources\ProductoResource;
use App\Models\Producto;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProductos extends ListRecords
{
    protected static string $resource = ProductoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Pestañas por tienda (motor): una pestaña por cada tienda existente + "Todas" y "Otro".
     */
    public function getTabs(): array
    {
        $tiendas = RastreadorFabrica::nombresParaBD();
        $tabs = [
            'todas' => Tab::make('Todas')
                ->badge(Producto::query()->count()),
        ];
        foreach ($tiendas as $nombre) {
            $tabs['tienda_' . str_replace(' ', '_', $nombre)] = Tab::make($nombre)
                ->badge(Producto::query()->where('tienda_origen', $nombre)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('tienda_origen', $nombre));
        }
        $tabs['otro'] = Tab::make('Otro')
            ->badge(Producto::query()->where('tienda_origen', 'Otro')->count())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('tienda_origen', 'Otro'));

        return $tabs;
    }
}
