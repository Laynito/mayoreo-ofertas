<?php

namespace App\Filament\Pages;

use App\Services\AdmitadService;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class CuponesAdmitadPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Cupones Admitad';

    protected static ?string $title = 'Cupones (Admitad)';

    protected static ?string $navigationGroup = 'Admitad';

    protected static string $view = 'filament.pages.cupones-admitad-page';

    protected static ?int $navigationSort = 11;

    public ?int $limit = 20;

    public ?int $offset = 0;

    public ?string $region = 'MX';

    public array $coupons = [];

    public array $meta = [];

    public ?string $error = null;

    public function getTitle(): string|Htmlable
    {
        return 'Cupones Admitad';
    }

    public function mount(AdmitadService $admitad): void
    {
        $this->loadCoupons($admitad);
    }

    public function loadCoupons(AdmitadService $admitad): void
    {
        $this->error = null;
        if (! config('services.admitad.client_id') || ! config('services.admitad.client_secret')) {
            $this->error = 'Configura ADMITAD_CLIENT_ID y ADMITAD_CLIENT_SECRET en .env';
            return;
        }
        $data = $admitad->getCoupons(
            $this->limit ?: 20,
            $this->offset ?: 0,
            $this->region ?: 'MX',
            'es'
        );
        $this->coupons = $data['results'] ?? [];
        $this->meta = $data['_meta'] ?? [];
    }

    public function refreshCoupons(AdmitadService $admitad): void
    {
        $this->loadCoupons($admitad);
    }

    public static function canAccess(): bool
    {
        return config('services.admitad.client_id') && config('services.admitad.client_secret');
    }
}
