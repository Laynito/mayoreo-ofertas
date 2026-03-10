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
        $config = is_array($data['configuracion'] ?? null) ? $data['configuracion'] : [];
        $urls = $config['urls'] ?? [];
        if (! is_array($urls)) {
            $urls = [];
        }
        $urls = array_values(array_filter(array_map('strval', $urls)));
        $data['urls_secciones'] = implode("\n", $urls);
        $data['configuracion'] = $config;
        unset($data['configuracion']['urls']);
        return $data;
    }

    public function mutateFormDataBeforeSave(array $data): array
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
        $urls = array_values(array_filter($urls));

        $existing = is_array($this->record->configuracion ?? null) ? $this->record->configuracion : [];
        $incoming = is_array($data['configuracion'] ?? null) ? $data['configuracion'] : [];
        $data['configuracion'] = array_merge($existing, $incoming);
        $data['configuracion']['urls'] = $urls;
        unset($data['urls_secciones']);
        return $data;
    }
}
