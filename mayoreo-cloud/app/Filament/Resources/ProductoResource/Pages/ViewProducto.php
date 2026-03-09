<?php

namespace App\Filament\Resources\ProductoResource\Pages;

use App\Filament\Resources\ProductoResource;
use App\Services\AffiliateService;
use Filament\Resources\Pages\ViewRecord;

class ViewProducto extends ViewRecord
{
    protected static string $resource = ProductoResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! empty($data['url_producto'])) {
            $data['url_afiliado'] = app(AffiliateService::class)->getCanonicalAffiliateLink($data['url_producto']);
        }
        return $data;
    }
}
