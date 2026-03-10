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
        $text = is_string($data['urls_secciones'] ?? null) ? trim($data['urls_secciones']) : '';
        $lines = $text === '' ? [] : preg_split('/\r\n|\r|\n/', $text);
        $urls = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && str_starts_with($line, 'https://')) {
                $urls[] = $line;
            }
        }
        $data['configuracion'] = is_array($data['configuracion'] ?? null) ? $data['configuracion'] : [];
        $data['configuracion']['urls'] = array_values(array_filter($urls));
        unset($data['urls_secciones']);
        return $data;
    }
}
