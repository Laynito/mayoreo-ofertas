<?php

namespace App\Filament\Resources\RedirectLinkResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ClicsRelationManager extends RelationManager
{
    protected static string $relationship = 'clics';

    protected static ?string $title = 'Clics';

    protected static ?string $modelLabel = 'Clic';

    protected static ?string $pluralModelLabel = 'Clics';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user_agent')
                    ->label('User-Agent')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record?->user_agent)
                    ->searchable(),
                Tables\Columns\TextColumn::make('clicked_at')
                    ->label('Fecha y hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('clicked_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
