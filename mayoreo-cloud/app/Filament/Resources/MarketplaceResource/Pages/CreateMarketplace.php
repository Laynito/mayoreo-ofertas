<?php

namespace App\Filament\Resources\MarketplaceResource\Pages;

use App\Filament\Resources\MarketplaceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketplace extends CreateRecord
{
    protected static string $resource = MarketplaceResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function mutateFormDataBeforeCreate(array $data): array
    {
        $urls = array_values(array_filter(array_column($data['urls_secciones'] ?? [], 'url')));
        $data['configuracion'] = $data['configuracion'] ?? [];
        $data['configuracion']['urls'] = $urls;
        unset($data['urls_secciones']);
        return $data;
    }
}
