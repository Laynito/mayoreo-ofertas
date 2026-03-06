<?php

namespace App\Filament\Resources\MercadoLibreWebhookLogResource\Pages;

use App\Filament\Resources\MercadoLibreWebhookLogResource;
use Filament\Resources\Pages\ListRecords;

class ListMercadoLibreWebhookLogs extends ListRecords
{
    protected static string $resource = MercadoLibreWebhookLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
