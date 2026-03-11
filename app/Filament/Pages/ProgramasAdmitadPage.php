<?php

namespace App\Filament\Pages;

use App\Services\AdmitadService;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class ProgramasAdmitadPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationLabel = 'Programas Admitad';

    protected static ?string $title = 'Programas de afiliados (Admitad)';

    protected static ?string $navigationGroup = 'Admitad';

    protected static string $view = 'filament.pages.programas-admitad-page';

    protected static ?int $navigationSort = 10;

    public ?int $limit = 50;

    public ?int $offset = 0;

    /** Filtro: '' = todos, 'active' = solo conectados, 'pending' = pendientes, 'declined' = rechazados */
    public string $connectionStatus = 'active';

    /** Filtro por idioma: null = todos, 'es' = español, 'en' = inglés, etc. */
    public ?string $language = 'es';

    public array $programs = [];

    public array $meta = [];

    public ?string $error = null;

    /** Opciones para el select de estado de conexión */
    public static function getConnectionStatusOptions(): array
    {
        return [
            '' => 'Todos',
            'active' => 'Solo conectados (recomendado para empezar)',
            'pending' => 'Pendientes de aprobación',
            'declined' => 'Rechazados',
        ];
    }

    /** Opciones para el select de idioma */
    public static function getLanguageOptions(): array
    {
        return [
            '' => 'Todos los idiomas',
            'es' => 'Español',
            'en' => 'English',
            'pt' => 'Português',
            'ru' => 'Русский',
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return 'Programas Admitad';
    }

    public function mount(AdmitadService $admitad): void
    {
        $this->loadPrograms($admitad);
    }

    public function loadPrograms(AdmitadService $admitad): void
    {
        $this->error = null;
        if (! config('services.admitad.client_id') || ! config('services.admitad.client_secret')) {
            $this->error = 'Configura ADMITAD_CLIENT_ID y ADMITAD_CLIENT_SECRET en .env';
            return;
        }
        $connectionStatus = $this->connectionStatus === '' ? null : $this->connectionStatus;
        $language = $this->language === '' ? null : $this->language;
        $limit = (int) ($this->limit ?: 50);
        $offset = (int) ($this->offset ?: 0);

        // Para "Solo conectados", "Pendientes" o "Rechazados" hace falta un espacio (website); la API filtra por ese espacio.
        if ($connectionStatus !== null) {
            $websites = $admitad->getWebsites();
            if (empty($websites)) {
                $this->programs = [];
                $this->meta = ['count' => 0, 'limit' => $limit, 'offset' => $offset];
                $this->error = 'Para filtrar por estado de conexión necesitas al menos un espacio publicitario en Admitad. Crea uno en tu cuenta (Admitad → Espacios publicitarios) y vuelve a aplicar el filtro. Mientras tanto usa "Todos" para ver el catálogo.';
                return;
            }
            $firstWebsiteId = (int) ($websites[0]['id'] ?? 0);
            $data = $admitad->getProgramsByWebsite($firstWebsiteId, $limit, $offset, $connectionStatus, $language);
        } else {
            $data = $admitad->getPrograms($limit, $offset, null, $language, null);
        }

        $this->programs = $data['results'] ?? [];
        $this->meta = $data['_meta'] ?? [];
    }

    public function refreshPrograms(AdmitadService $admitad): void
    {
        $this->loadPrograms($admitad);
    }

    public static function canAccess(): bool
    {
        return config('services.admitad.client_id') && config('services.admitad.client_secret');
    }
}
