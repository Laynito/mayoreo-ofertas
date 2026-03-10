<?php

namespace App\Filament\Pages;

use App\Models\Producto;
use App\Services\AffiliateService;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

class PreciosBajos extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationLabel = 'Precios Bajos';

    protected static ?string $title = 'Precios Bajos';

    protected static ?string $slug = 'precios-bajos';

    protected static string $view = 'filament.pages.precios-bajos';

    #[Url]
    public bool $isTableReordering = false;

    #[Url]
    public ?array $tableFilters = null;

    #[Url]
    public ?string $tableGrouping = null;

    #[Url]
    public ?string $tableGroupingDirection = null;

    #[Url]
    public $tableSearch = '';

    #[Url]
    public ?string $tableSortColumn = null;

    #[Url]
    public ?string $tableSortDirection = null;

    public static function getNavigationSort(): ?int
    {
        return -1;
    }

    public function mount(): void
    {
        $this->mountInteractsWithTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Producto::query())
            ->columns([
                Tables\Columns\ImageColumn::make('url_imagen')
                    ->label('Imagen')
                    ->circular()
                    ->checkFileExistence(false),
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Producto')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('precio_actual')
                    ->label('Precio')
                    ->money('MXN')
                    ->sortable(),
                Tables\Columns\TextColumn::make('precio_original')
                    ->label('P. original')
                    ->money('MXN')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('descuento')
                    ->label('%')
                    ->suffix('%')
                    ->sortable()
                    ->badge()
                    ->color(fn (?int $state): string => $state > 20 ? 'success' : ($state > 0 ? 'warning' : 'gray')),
                Tables\Columns\TextColumn::make('tienda')
                    ->label('Tienda')
                    ->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->actions([
                Tables\Actions\Action::make('ver_oferta')
                    ->label('Ver oferta')
                    ->url(fn (Producto $record): string => app(AffiliateService::class)->getCanonicalAffiliateLink($record->url_producto))
                    ->openUrlInNewTab()
                    ->icon('heroicon-o-arrow-top-right-on-square'),
            ])
            ->bulkActions([]);
    }
}
