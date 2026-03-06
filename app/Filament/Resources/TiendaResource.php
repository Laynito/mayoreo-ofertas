<?php

namespace App\Filament\Resources;

use App\Fabrica\RastreadorFabrica;
use App\Filament\Resources\TiendaResource\Pages;
use App\Jobs\EjecutarRastreoTiendaJob;
use App\Models\Tienda;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TiendaResource extends Resource
{
    protected static ?string $model = Tienda::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Tiendas';

    protected static ?string $modelLabel = 'Tienda';

    protected static ?string $pluralModelLabel = 'Tiendas';

    protected static ?string $navigationGroup = 'Administración';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identificación')
                    ->schema([
                        Forms\Components\TextInput::make('nombre')
                            ->label('Nombre')
                            ->placeholder('Ej. Costco, Mercado Libre')
                            ->required()
                            ->maxLength(80)
                            ->unique(ignoreRecord: true),
                        Forms\Components\Select::make('clase_motor')
                            ->label('Clase del motor')
                            ->options(Tienda::clasesMotorDisponibles())
                            ->required()
                            ->searchable()
                            ->helperText('Clase que implementa el rastreo para esta tienda (App\\Motores\\*Motor).'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Estado')
                    ->schema([
                        Forms\Components\Toggle::make('activo')
                            ->label('Rastreo activo')
                            ->helperText('Si está desactivada, el rastreo de esta tienda se omite (no se borran productos).')
                            ->default(true)
                            ->inline(false),
                    ]),

                Forms\Components\Section::make('Configuración por tienda')
                    ->description('URL de ofertas y selector CSS para scraping (opcional; algunos motores los definen en código).')
                    ->schema([
                        Forms\Components\TextInput::make('url_ofertas')
                            ->label('URL de ofertas')
                            ->url()
                            ->placeholder('https://...')
                            ->maxLength(2048)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('selector_css_principal')
                            ->label('Selector CSS principal')
                            ->placeholder('Ej. .product-card, [data-product]')
                            ->maxLength(512)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('notas')
                            ->label('Notas')
                            ->placeholder('Observaciones, recordatorios o datos extra de esta tienda.')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Tienda')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('clase_motor')
                    ->label('Motor')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->tooltip(fn (Tienda $record): string => $record->clase_motor)
                    ->sortable(),
                Tables\Columns\IconColumn::make('activo')
                    ->label('Rastreo')
                    ->boolean()
                    ->sortable()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                Tables\Columns\TextColumn::make('productos_count')
                    ->label('Productos')
                    ->counts('productos')
                    ->sortable(),
                Tables\Columns\TextColumn::make('url_ofertas')
                    ->label('URL ofertas')
                    ->limit(40)
                    ->tooltip(fn (Tienda $record): ?string => $record->url_ofertas)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('selector_css_principal')
                    ->label('Selector CSS')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('notas')
                    ->label('Notas')
                    ->limit(40)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Rastreo activo')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Pausadas'),
            ])
            ->actions([
                Tables\Actions\Action::make('rastrear')
                    ->label('Ejecutar rastreo')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->action(function (Tienda $record): void {
                        EjecutarRastreoTiendaJob::dispatch($record->nombre)->onQueue('default');
                        \Filament\Notifications\Notification::make()
                            ->title('Rastreo encolado')
                            ->body("Se ha encolado el rastreo de «{$record->nombre}». Se ejecutará en breve (revisa el worker o los logs).")
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Ejecutar rastreo ahora')
                    ->modalDescription(fn (Tienda $record): string => "Se lanzará el comando rastreo:tienda para «{$record->nombre}» en segundo plano. Los productos se actualizarán y las ofertas se encolarán para Telegram."),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('toggle_activo')
                    ->label(fn (Tienda $record): string => $record->activo ? 'Pausar' : 'Activar')
                    ->icon(fn (Tienda $record): string => $record->activo ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color(fn (Tienda $record): string => $record->activo ? 'warning' : 'success')
                    ->action(function (Tienda $record): void {
                        $record->update(['activo' => ! $record->activo]);
                        \Filament\Notifications\Notification::make()
                            ->title($record->activo ? 'Tienda activada' : 'Tienda pausada')
                            ->body($record->activo ? 'El rastreo volverá a incluir esta tienda.' : 'El rastreo omitirá esta tienda hasta que la actives.')
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTiendas::route('/'),
            'create' => Pages\CreateTienda::route('/create'),
            'edit' => Pages\EditTienda::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}
