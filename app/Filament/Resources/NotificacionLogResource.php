<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificacionLogResource\Pages;
use App\Jobs\EnviarOfertaTelegramJob;
use App\Models\NotificacionLog;
use App\Models\Producto;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificacionLogResource extends Resource
{
    protected static ?string $model = NotificacionLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Log de notificaciones';

    protected static ?string $modelLabel = 'Registro de notificación';

    protected static ?string $pluralModelLabel = 'Log de notificaciones';

    protected static ?string $navigationGroup = 'Administración';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('producto.nombre')
                    ->label('Producto')
                    ->limit(50)
                    ->tooltip(fn (NotificacionLog $record): ?string => $record->producto?->nombre)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('producto', fn (Builder $q): Builder =>
                            $q->where('nombre', 'like', '%' . $search . '%'));
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('tienda')
                    ->label('Tienda')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        NotificacionLog::ESTADO_ENVIADO => 'success',
                        NotificacionLog::ESTADO_FALLIDO => 'danger',
                        NotificacionLog::ESTADO_OMITIDO => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        NotificacionLog::ESTADO_ENVIADO => 'Enviado',
                        NotificacionLog::ESTADO_FALLIDO => 'Fallido',
                        NotificacionLog::ESTADO_OMITIDO => 'Omitido',
                        default => $state,
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('mensaje_error')
                    ->label('Mensaje de error')
                    ->limit(60)
                    ->tooltip(fn (NotificacionLog $record): ?string => $record->mensaje_error)
                    ->placeholder('—')
                    ->wrap(),
                Tables\Columns\TextColumn::make('chat_id')
                    ->label('Chat ID')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('reenviar')
                    ->label('Re-enviar a Telegram')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (NotificacionLog $record): bool =>
                        $record->producto_id !== null && $record->producto !== null)
                    ->action(function (NotificacionLog $record): void {
                        $producto = $record->producto;
                        if ($producto instanceof Producto) {
                            EnviarOfertaTelegramJob::dispatch($producto)->onQueue('high');
                            \Filament\Notifications\Notification::make()
                                ->title('Reenviado')
                                ->body('El producto se ha encolado para envío a Telegram.')
                                ->success()
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Re-enviar oferta a Telegram')
                    ->modalDescription('Se volverá a encolar el envío de este producto al canal configurado.'),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificacionLogs::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}
