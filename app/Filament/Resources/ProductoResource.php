<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductoResource\Pages;
use App\Models\Producto;
use App\Services\AffiliateService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductoResource extends Resource
{
    protected static ?string $model = Producto::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Productos';

    protected static ?string $modelLabel = 'Producto';

    protected static ?string $pluralModelLabel = 'Productos';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nombre')
                    ->label('Nombre')
                    ->disabled(),
                Forms\Components\TextInput::make('sku')
                    ->label('SKU')
                    ->disabled(),
                Forms\Components\TextInput::make('precio_actual')
                    ->label('Precio actual')
                    ->disabled(),
                Forms\Components\TextInput::make('precio_original')
                    ->label('Precio original')
                    ->disabled(),
                Forms\Components\TextInput::make('descuento')
                    ->label('Descuento (%)')
                    ->disabled(),
                Forms\Components\TextInput::make('url_producto')
                    ->label('URL producto')
                    ->disabled()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('url_afiliado')
                    ->label('Link afiliado')
                    ->disabled()
                    ->columnSpanFull()
                    ->hint('Si ves una URL con click1.mercadolibre…, ejecuta: php artisan app:refresh-affiliate-links'),
                Forms\Components\TextInput::make('url_imagen')
                    ->label('URL imagen')
                    ->disabled()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('tienda')
                    ->label('Tienda')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('url_imagen')
                    ->label('')
                    ->circular()
                    ->size(36)
                    ->checkFileExistence(false)
                    ->width('2.5rem'),
                Tables\Columns\TextColumn::make('nombre')
                    ->searchable()
                    ->limit(42)
                    ->wrap()
                    ->tooltip(fn (Producto $record): string => $record->nombre),
                Tables\Columns\TextColumn::make('sku')
                    ->searchable()
                    ->copyable()
                    ->width('6rem'),
                Tables\Columns\TextColumn::make('precio_actual')
                    ->money('MXN')
                    ->sortable()
                    ->width('6rem'),
                Tables\Columns\TextColumn::make('precio_original')
                    ->money('MXN')
                    ->sortable()
                    ->placeholder('—')
                    ->width('6rem'),
                Tables\Columns\TextColumn::make('descuento')
                    ->suffix('%')
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => $state > 20 ? 'success' : ($state > 0 ? 'warning' : 'gray'))
                    ->width('4rem'),
                Tables\Columns\TextColumn::make('url_afiliado')
                    ->label('Link afiliado')
                    ->limit(40)
                    ->url(fn (Producto $record): ?string => $record->url_afiliado ?: ($record->url_producto ? app(AffiliateService::class)->getAffiliateLinkForProduct($record->url_producto, $record->tienda) : null))
                    ->openUrlInNewTab()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tienda')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match (true) {
                        stripos((string) $state, 'mercado') !== false => 'ML',
                        stripos((string) $state, 'coppel') !== false => 'Coppel',
                        default => $state ?? '—',
                    })
                    ->tooltip(fn (?string $state): string => $state ?? '')
                    ->width('4.5rem'),
            ])
            ->striped()
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('ver_oferta')
                    ->label('Ver Oferta')
                    ->url(fn (Producto $record): string => $record->url_afiliado ?: app(AffiliateService::class)->getAffiliateLinkForProduct($record->url_producto, $record->tienda))
                    ->openUrlInNewTab()
                    ->icon('heroicon-o-arrow-top-right-on-square'),
                Tables\Actions\Action::make('enviar_telegram')
                    ->label('Enviar a Telegram')
                    ->icon('heroicon-o-paper-airplane')
                    ->action(function (Producto $record): void {
                        \App\Jobs\ProcessTelegramPost::dispatch($record);
                        \Filament\Notifications\Notification::make()
                            ->title('Encolado')
                            ->body('El producto se enviará al canal de Telegram en breve.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductos::route('/'),
            'view' => Pages\ViewProducto::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
