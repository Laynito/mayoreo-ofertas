<?php

namespace App\Filament\Resources\RedirectLinkResource\Pages;

use App\Filament\Resources\RedirectLinkResource;
use Filament\Resources\Pages\ViewRecord;

class ViewRedirectLink extends ViewRecord
{
    protected static string $resource = RedirectLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}