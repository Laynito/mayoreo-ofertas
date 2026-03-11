<?php

namespace App\Filament\Pages;

use App\Services\AdmitadService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class GenerarEnlaceAdmitadPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Generar enlace';

    protected static ?string $title = 'Generar enlace de afiliado (Deeplink)';

    protected static ?string $navigationGroup = 'Admitad';

    protected static string $view = 'filament.pages.generar-enlace-admitad-page';

    protected static ?int $navigationSort = 12;

    public ?int $website_id = null;

    public ?int $campaign_id = null;

    public ?string $urls_text = null;

    public ?string $subid = null;

    public array $generated_links = [];

    public function getTitle(): string|Htmlable
    {
        return 'Generar enlace Admitad';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('website_id')
                    ->label('ID del espacio publicitario (website_id)')
                    ->numeric()
                    ->required()
                    ->helperText('Lo ves en Admitad → Espacios publicitarios, o con GET /websites/v2/'),
                TextInput::make('campaign_id')
                    ->label('ID del programa (campaign_id)')
                    ->numeric()
                    ->required()
                    ->helperText('ID del programa de afiliados, p. ej. desde la página Programas Admitad'),
                Textarea::make('urls_text')
                    ->label('URLs a convertir (una por línea)')
                    ->placeholder("https://ejemplo.com/producto/1\nhttps://ejemplo.com/producto/2")
                    ->required()
                    ->helperText('Máximo 200 URLs. Cada una se convertirá en enlace de afiliado.'),
                TextInput::make('subid')
                    ->label('Subid (opcional)')
                    ->maxLength(50),
            ])
            ->statePath('');
    }

    public function generate(AdmitadService $admitad): void
    {
        $this->validate([
            'website_id' => 'required|integer|min:1',
            'campaign_id' => 'required|integer|min:1',
            'urls_text' => 'required|string',
        ]);

        $lines = array_filter(array_map('trim', explode("\n", $this->urls_text ?? '')));
        $urls = array_values(array_filter($lines, fn ($u) => $u !== '' && (str_starts_with($u, 'http://') || str_starts_with($u, 'https://'))));

        if (empty($urls)) {
            Notification::make()
                ->title('No hay URLs válidas')
                ->body('Escribe al menos una URL que empiece con http:// o https:// (una por línea).')
                ->danger()
                ->send();
            return;
        }

        $links = $admitad->generateDeeplinks(
            (int) $this->website_id,
            (int) $this->campaign_id,
            $urls,
            $this->subid ?: null
        );

        if (empty($links)) {
            Notification::make()
                ->title('No se generaron enlaces')
                ->body('La API no devolvió enlaces. Comprueba website_id, campaign_id y que el scope "deeplink_generator" esté en ADMITAD_SCOPE.')
                ->warning()
                ->send();
            $this->generated_links = [];
            return;
        }

        $this->generated_links = $links;
        Notification::make()
            ->title('Enlaces generados')
            ->body(count($links) . ' enlace(s) de afiliado listo(s).')
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return config('services.admitad.client_id') && config('services.admitad.client_secret');
    }
}
