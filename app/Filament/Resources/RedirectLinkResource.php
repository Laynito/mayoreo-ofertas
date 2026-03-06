<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RedirectLinkResource\Pages;
use App\Filament\Resources\RedirectLinkResource\RelationManagers\ClicsRelationManager;
use App\Models\RedirectLink;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RedirectLinkResource extends Resource
{
    protected static ?string $model = RedirectLink::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Enlaces de redirección';

    protected static ?string $modelLabel = 'Enlace de redirección';

    protected static ?string $pluralModelLabel = 'Enlaces de redirección';

    protected static ?string $navigationGroup = 'Afiliados';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Código copiado'),
                Tables\Columns\TextColumn::make('url_destino')
                    ->label('URL destino')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record?->url_destino)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subid')
                    ->label('SubID')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('clics_count')
                    ->label('Clics')
                    ->counts('clics')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->withCount('clics')->orderBy('clics_count', $direction);
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('subid')
                    ->label('Canal (SubID)')
                    ->options([
                        'Mayoreo_Cloud_Bot' => 'Mayoreo_Cloud_Bot',
                        'Canal_Principal' => 'Canal_Principal',
                        'Telegram_Bot' => 'Telegram_Bot',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            ClicsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRedirectLinks::route('/'),
            'view' => Pages\ViewRedirectLink::route('/{record}'),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('codigo')->label('Código'),
                TextEntry::make('url_destino')->label('URL destino')->url()->openUrlInNewTab(true)->columnSpanFull(),
                TextEntry::make('subid')->label('SubID')->badge(),
                TextEntry::make('clics_count')->label('Total clics'),
                TextEntry::make('created_at')->label('Creado')->dateTime('d/m/Y H:i'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('clics');
    }
}
