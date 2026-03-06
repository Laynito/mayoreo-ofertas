<?php

namespace App\Filament\Resources\NotificacionLogResource\Pages;

use App\Filament\Resources\NotificacionLogResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificacionLogs extends ListRecords
{
    protected static string $resource = NotificacionLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
