<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MercadoLibreWebhookLogResource\Pages;
use App\Models\MercadoLibreWebhookLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MercadoLibreWebhookLogResource extends Resource
{
    protected static ?string $model = MercadoLibreWebhookLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationLabel = 'Logs de Webhook';

    protected static ?string $modelLabel = 'Ping webhook ML';

    protected static ?string $pluralModelLabel = 'Logs de Webhook';

    protected static ?string $navigationGroup = 'Administración';

    protected static ?string $slug = 'mercado-libre-webhook-logs';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Recibido')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('topic')
                    ->label('Topic')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('resource')
                    ->label('Resource')
                    ->limit(60)
                    ->tooltip(fn (MercadoLibreWebhookLog $record): ?string => $record->resource)
                    ->placeholder('—')
                    ->url(fn (MercadoLibreWebhookLog $record): ?string =>
                        $record->resource && str_starts_with($record->resource, 'http') ? $record->resource : null)
                    ->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('sent_time')
                    ->label('Sent time (ML)')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('received_at', 'desc')
            ->defaultPaginationPageOption(20)
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMercadoLibreWebhookLogs::route('/'),
        ];
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
