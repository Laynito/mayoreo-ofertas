<?php

namespace App\Filament\Pages;

use App\Models\Configuracion;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;

class AjustesServicios extends Page
{
    use InteractsWithFormActions;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Ajustes';

    protected static ?string $title = 'Ajustes de servicios';

    protected static ?string $navigationGroup = 'Sistema';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.ajustes-servicios';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'telegram_token' => Configuracion::obtener(Configuracion::CLAVE_TELEGRAM_TOKEN) ?: config('services.telegram.token'),
            'telegram_chat_id' => Configuracion::obtener(Configuracion::CLAVE_TELEGRAM_CHAT_ID) ?: config('services.telegram.chat_id'),
            'ml_app_id' => Configuracion::obtener(Configuracion::CLAVE_ML_APP_ID) ?: config('services.mercado_libre.app_id'),
            'ml_secret_key' => Configuracion::obtener(Configuracion::CLAVE_ML_SECRET_KEY) ?: config('services.mercado_libre.secret_key'),
            'ml_affiliate_id' => Configuracion::obtener(Configuracion::CLAVE_ML_AFFILIATE_ID) ?: config('services.mercado_libre.affiliate_id', '187001804'),
            'amazon_tag' => Configuracion::obtener(Configuracion::CLAVE_AMAZON_TAG) ?: config('services.amazon_tag', 'micosmtics-20'),
            'proxy_habilitado' => Configuracion::obtener(Configuracion::CLAVE_PROXY_HABILITADO) ?? (config('services.proxy_url') !== null && config('services.proxy_url') !== ''),
        ]);
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected function getFormSchema(): array
    {
        $proxyUrl = config('services.proxy_url');
        $proxyInfo = $proxyUrl ? 'URL en .env: ' . preg_replace('#://[^@]+@#', '://***@', $proxyUrl) : 'PROXY_URL no definido en .env.';

        return [
            Forms\Components\Section::make('Telegram')
                ->description('Token del bot y chat donde se envían las ofertas. Si se rellenan aquí, tienen prioridad sobre .env.')
                ->schema([
                    Forms\Components\TextInput::make('telegram_token')
                        ->label('TELEGRAM_BOT_TOKEN')
                        ->password()
                        ->revealable()
                        ->placeholder('Dejar vacío para usar el valor del .env'),
                    Forms\Components\TextInput::make('telegram_chat_id')
                        ->label('TELEGRAM_CHAT_ID')
                        ->placeholder('Ej: -1001234567890')
                        ->helperText('Chat o canal donde recibir las ofertas. Dejar vacío para usar .env'),
                ])
                ->columns(1),

            Forms\Components\Section::make('Mercado Libre')
                ->description('Credenciales de la app (OAuth) e ID de afiliado para enlaces. El sistema usa scraping con proxy cuando la API no está aprobada.')
                ->schema([
                    Forms\Components\TextInput::make('ml_app_id')
                        ->label('APP_ID (ML_APP_ID)')
                        ->placeholder('Dejar vacío para usar .env'),
                    Forms\Components\TextInput::make('ml_secret_key')
                        ->label('SECRET_KEY (ML_SECRET_KEY)')
                        ->password()
                        ->revealable()
                        ->placeholder('Dejar vacío para usar .env'),
                    Forms\Components\TextInput::make('ml_affiliate_id')
                        ->label('ID afiliado (ML_AFFILIATE_ID)')
                        ->placeholder('Ej: 187001804')
                        ->helperText('Solo para enlaces: se añade &micosmtics=XXX a las URLs que se envían a Telegram. No interviene en la prueba de conexión a la API (esa usa el token OAuth y el proxy).'),
                ])
                ->columns(1),

            Forms\Components\Section::make('Amazon')
                ->schema([
                    Forms\Components\TextInput::make('amazon_tag')
                        ->label('ASSOCIATE_TAG (AMAZON_TAG)')
                        ->placeholder('Ej: micosmtics-20')
                        ->helperText('Tag de afiliado que se añade a los enlaces de Amazon.'),
                ])
                ->columns(1),

            Forms\Components\Section::make('Proxy')
                ->description('Activa o desactiva el uso del proxy global (PROXY_URL del .env). El consumo se revisa en el panel de tu proveedor (Smartproxy, etc.).')
                ->schema([
                    Forms\Components\Toggle::make('proxy_habilitado')
                        ->label('Proxy global activado')
                        ->helperText('Si está desactivado, el rastreo no usará proxy aunque esté definido en .env.')
                        ->default(true),
                    Forms\Components\Placeholder::make('proxy_info')
                        ->label('Estado')
                        ->content($proxyInfo),
                ])
                ->columns(1),
        ];
    }

    public function getFormStatePath(): ?string
    {
        return 'data';
    }

    /**
     * @return array<Action | \Filament\Actions\ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('guardar')
                ->label('Guardar ajustes')
                ->submit('guardar'),
        ];
    }

    public function guardar(): void
    {
        $state = $this->form->getState();

        Configuracion::guardar(Configuracion::CLAVE_TELEGRAM_TOKEN, (string) ($state['telegram_token'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_TELEGRAM_CHAT_ID, (string) ($state['telegram_chat_id'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_ML_APP_ID, (string) ($state['ml_app_id'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_ML_SECRET_KEY, (string) ($state['ml_secret_key'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_ML_AFFILIATE_ID, (string) ($state['ml_affiliate_id'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_AMAZON_TAG, (string) ($state['amazon_tag'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_PROXY_HABILITADO, (bool) ($state['proxy_habilitado'] ?? true));

        Configuracion::limpiarCache();

        Notification::make()
            ->title('Ajustes guardados')
            ->body('Los valores de la base de datos tienen prioridad sobre el .env. Reinicia el worker si está corriendo: sudo supervisorctl restart mayoreo-worker:*')
            ->success()
            ->send();
    }
}
