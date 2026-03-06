<?php

namespace App\Filament\Resources;

use App\Fabrica\RastreadorFabrica;
use App\Filament\Resources\ProductoResource\Pages;
use App\Models\Producto;
use App\Models\Tienda;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductoResource extends Resource
{
    protected static ?string $model = Producto::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Productos';

    protected static ?string $modelLabel = 'Producto';

    protected static ?string $pluralModelLabel = 'Productos';

    /**
     * URL de la imagen del producto para mostrar en la tabla (captura guardada o imagen del motor).
     * Convierte rutas relativas (storage/...) a URL absoluta para que Filament la muestre bien.
     */
    public static function urlImagenProducto(Producto $record): ?string
    {
        $url = $record->captura_url ?: $record->imagen_url;
        if ($url === null || $url === '') {
            return null;
        }
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return asset(ltrim($url, '/'));
        }
        return $url;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('General')
                    ->schema([
                        Forms\Components\Select::make('tienda_id')
                            ->label('Tienda')
                            ->relationship('tienda', 'nombre')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('nombre')->required()->maxLength(80),
                                Forms\Components\Select::make('clase_motor')->options(Tienda::clasesMotorDisponibles())->required()->searchable(),
                                Forms\Components\Toggle::make('activo')->default(true),
                            ])
                            ->helperText('Si está vacío, se usa "Tienda origen" como respaldo.'),
                        Forms\Components\Select::make('tienda_origen')
                            ->label('Tienda origen (texto)')
                            ->options(self::opcionesTiendas())
                            ->searchable()
                            ->required()
                            ->helperText('Nombre usado en rastreo; debe coincidir con la tienda asignada.'),
                        Forms\Components\TextInput::make('sku_tienda')
                            ->label('SKU tienda')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('nombre')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('imagen_url')
                            ->label('URL de imagen')
                            ->url()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Precios')
                    ->schema([
                        Forms\Components\TextInput::make('precio_original')
                            ->label('Precio original')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->prefix('$'),
                        Forms\Components\TextInput::make('precio_oferta')
                            ->label('Precio oferta')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('$'),
                        Forms\Components\TextInput::make('porcentaje_ahorro')
                            ->label('% ahorro')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                        Forms\Components\TextInput::make('stock_disponible')
                            ->label('Stock disponible')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Forms\Components\DateTimePicker::make('ultima_actualizacion_precio')
                            ->label('Última actualización de precio'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Afiliación')
                    ->schema([
                        Forms\Components\TextInput::make('url_original')
                            ->label('URL original')
                            ->url()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('url_afiliado')
                            ->label('URL afiliado (Admitad)')
                            ->url()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Configuraciones')
                    ->description('Control de descuentos a nivel de producto.')
                    ->schema([
                        Forms\Components\Toggle::make('permite_descuento_adicional')
                            ->label('Permitir descuento adicional')
                            ->helperText('Si está desactivado, no se aplicará ningún descuento extra sobre el precio de oferta; se mostrará siempre el precio base.')
                            ->default(true)
                            ->inline(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('imagen_producto')
                    ->label('FOTO')
                    ->rounded()
                    ->getStateUsing(fn (Producto $record): ?string => self::urlImagenProducto($record))
                    ->defaultImageUrl(fn ($record) => 'https://ui-avatars.com/api/?name=' . urlencode($record->nombre ?? '') . '&size=64'),
                Tables\Columns\TextColumn::make('nombre')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('tienda.nombre')
                    ->label('Tienda')
                    ->placeholder(fn (Producto $record): string => $record->tienda_origen ?? '—')
                    ->badge()
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('tienda', fn (Builder $q): Builder =>
                            $q->where('nombre', 'like', '%' . $search . '%'))
                            ->orWhere('tienda_origen', 'like', '%' . $search . '%');
                    }),
                Tables\Columns\TextColumn::make('precio_actual')
                    ->label('Precio actual')
                    ->getStateUsing(fn (Producto $record): string => $record->precio_oferta
                        ? '$' . number_format((float) $record->precio_oferta, 2)
                        : '$' . number_format((float) $record->precio_original, 2))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw("COALESCE(precio_oferta, precio_original) {$direction}");
                    }),
                Tables\Columns\TextColumn::make('super_oferta')
                    ->label('')
                    ->badge()
                    ->getStateUsing(fn (Producto $record): ?string => ($record->porcentaje_ahorro && (float) $record->porcentaje_ahorro > 50)
                        ? 'Súper Oferta'
                        : null)
                    ->color('success')
                    ->placeholder(''),
                Tables\Columns\TextColumn::make('porcentaje_ahorro')
                    ->label('% ahorro')
                    ->suffix('%')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('stock_disponible')
                    ->label('Stock')
                    ->sortable()
                    ->alignCenter(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tienda_id')
                    ->label('Tienda')
                    ->relationship('tienda', 'nombre')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('tienda_origen')
                    ->label('Tienda (texto)')
                    ->options(self::opcionesTiendas()),
                Tables\Filters\Filter::make('con_stock')
                    ->label('Con stock')
                    ->query(fn (Builder $query): Builder => $query->where('stock_disponible', '>', 0)),
                Tables\Filters\Filter::make('sin_stock')
                    ->label('Sin stock')
                    ->query(fn (Builder $query): Builder => $query->where('stock_disponible', '<=', 0)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Opciones de tienda (todas las de RastreadorFabrica + Otro para productos manuales).
     */
    public static function opcionesTiendas(): array
    {
        return RastreadorFabrica::tiendasParaMenu();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductos::route('/'),
            'create' => Pages\CreateProducto::route('/create'),
            'view' => Pages\ViewProducto::route('/{record}'),
            'edit' => Pages\EditProducto::route('/{record}/edit'),
        ];
    }
}
