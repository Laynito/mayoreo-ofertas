<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EstadoMotorResource\Pages;
use App\Models\EstadoMotor;
use App\Services\EstadoMotorService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EstadoMotorResource extends Resource
{
    protected static ?string $model = EstadoMotor::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'Estado de motores';

    protected static ?string $modelLabel = 'Estado de motor';

    protected static ?string $pluralModelLabel = 'Estado de motores';

    protected static ?string $navigationGroup = 'Rastreo';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('nombre_tienda')
                    ->label('Tienda')
                    ->disabled(),
                Forms\Components\Select::make('estado')
                    ->label('Estado')
                    ->options([
                        EstadoMotor::ESTADO_ACTIVO => 'Activo',
                        EstadoMotor::ESTADO_BLOQUEADO => 'Bloqueado',
                        EstadoMotor::ESTADO_FALLO_TEMPORAL => 'Fallo temporal',
                    ])
                    ->required(),
                Forms\Components\Textarea::make('ultimo_error')
                    ->label('Último error')
                    ->disabled()
                    ->rows(4)
                    ->columnSpanFull(),
                Forms\Components\DateTimePicker::make('ultima_actualizacion')
                    ->label('Última actualización')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre_tienda')
                    ->label('Tienda')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        EstadoMotor::ESTADO_ACTIVO => 'success',
                        EstadoMotor::ESTADO_BLOQUEADO => 'danger',
                        EstadoMotor::ESTADO_FALLO_TEMPORAL => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('ultimo_error')
                    ->label('Último error')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record?->ultimo_error),
                Tables\Columns\TextColumn::make('ultima_actualizacion')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('reactivar')
                    ->label('Reactivar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (EstadoMotor $record): bool => $record->estado !== EstadoMotor::ESTADO_ACTIVO)
                    ->action(function (EstadoMotor $record): void {
                        app(EstadoMotorService::class)->reactivar($record->nombre_tienda);
                        $record->refresh();
                    })
                    ->requiresConfirmation(),
            ])
            ->defaultSort('ultima_actualizacion', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEstadoMotores::route('/'),
            'edit' => Pages\EditEstadoMotor::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery();
    }
}
