<?php

namespace App\Filament\Resources\MarketplaceResource\Pages;

use App\Filament\Resources\MarketplaceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMarketplace extends EditRecord
{
    protected static string $resource = MarketplaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function mutateFormDataBeforeFill(array $data): array
    {
        $config = $data['configuracion'] ?? [];
        $urls = $config['urls'] ?? [];
        $data['urls_secciones'] = array_map(fn (string $url): array => ['url' => $url], array_values(array_filter($urls)));
        unset($data['configuracion']['urls']);
        return $data;
    }

    public function mutateFormDataBeforeSave(array $data): array
    {
        $urls = array_values(array_filter(array_column($data['urls_secciones'] ?? [], 'url')));
        $data['configuracion'] = $data['configuracion'] ?? [];
        $data['configuracion']['urls'] = $urls;
        unset($data['urls_secciones']);
        return $data;
    }
}
