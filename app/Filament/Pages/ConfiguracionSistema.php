<?php

namespace App\Filament\Pages;

use App\Models\Configuracion;
use App\Services\DiagnosticoConexionService;
use App\Services\MercadoLibreTokenService;
use App\Services\NotificadorTelegram;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;

class ConfiguracionSistema extends Page
{
    use InteractsWithFormActions;

    protected static ?string $navigationIcon = 'heroicon-o-cog-8-tooth';

    protected static ?string $navigationLabel = 'Centro de Control';

    protected static ?string $title = 'Configuración del sistema';

    protected static ?string $navigationGroup = 'Administración';

    protected static string $view = 'filament.pages.configuracion-sistema';

    public ?array $data = [];

    /** Resultados del diagnóstico (para mostrar en la pestaña). */
    public ?string $diagnosticoMlResultado = null;

    /** Si la última prueba de API ML falló: mostrar aviso "modo scraping" (naranja). */
    public bool $diagnosticoMlModoScraping = false;

    public ?string $diagnosticoProxyResultado = null;

    public ?string $diagnosticoSimuladorResultado = null;

    public ?string $diagnosticoSimuladorTipo = null;

    /** Resultado del botón Verificar Proxy en la pestaña Proxy y Rastreo. */
    public ?string $proxyVerificadoResultado = null;

    public function mount(): void
    {
        $this->form->fill($this->valoresIniciales());
    }

    private function valoresIniciales(): array
    {
        $proxyEnv = config('services.proxy_url');
        return [
            'telegram_token' => Configuracion::obtener(Configuracion::CLAVE_TELEGRAM_TOKEN) ?: config('services.telegram.token'),
            'telegram_chat_id' => Configuracion::obtener(Configuracion::CLAVE_TELEGRAM_CHAT_ID) ?: config('services.telegram.chat_id'),
            'telegram_chat_id_premium' => Configuracion::obtener(Configuracion::CLAVE_TELEGRAM_CHAT_ID_PREMIUM) ?: config('services.telegram.chat_id_premium'),
            'ml_affiliate_id' => Configuracion::obtener(Configuracion::CLAVE_ML_AFFILIATE_ID) ?: config('services.mercado_libre.affiliate_id', '187001804'),
            'amazon_tag' => Configuracion::obtener(Configuracion::CLAVE_AMAZON_TAG) ?: config('services.amazon_tag', 'micosmtics-20'),
            'admitad_base_url' => Configuracion::obtener(Configuracion::CLAVE_ADMITAD_BASE_URL) ?: config('services.admitad.base_url'),
            'admitad_publisher_id' => Configuracion::obtener(Configuracion::CLAVE_ADMITAD_PUBLISHER_ID) ?: (config('services.admitad.website_id') ?? config('services.admitad.id')),
            'ml_app_id' => Configuracion::obtener(Configuracion::CLAVE_ML_APP_ID) ?: config('services.mercado_libre.app_id'),
            'ml_secret_key' => Configuracion::obtener(Configuracion::CLAVE_ML_SECRET_KEY) ?: config('services.mercado_libre.secret_key'),
            'proxy_habilitado' => Configuracion::obtener(Configuracion::CLAVE_PROXY_HABILITADO) ?? ($proxyEnv !== null && $proxyEnv !== ''),
            'proxy_url' => Configuracion::obtener(Configuracion::CLAVE_PROXY_URL) ?: $proxyEnv,
            'simulador_url' => '',
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Tabs::make('configuracion')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Telegram')
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->schema([
                                Forms\Components\TextInput::make('telegram_token')
                                    ->label('Bot Token')
                                    ->password()
                                    ->revealable()
                                    ->placeholder('Dejar vacío para usar .env'),
                                Forms\Components\TextInput::make('telegram_chat_id')
                                    ->label('Chat ID Free')
                                    ->placeholder('Ej: -1001234567890')
                                    ->helperText('Canal donde se envían todas las ofertas.'),
                                Forms\Components\TextInput::make('telegram_chat_id_premium')
                                    ->label('Chat ID Premium')
                                    ->placeholder('Opcional. Ofertas con % ≥ umbral Premium (Ajustes notificaciones).')
                                    ->helperText('Si está vacío, solo se usa Chat ID Free.'),
                                Forms\Components\Actions::make([
                                    Forms\Components\Actions\Action::make('probar_conexion')
                                        ->label('Probar conexión')
                                        ->icon('heroicon-o-paper-airplane')
                                        ->action(function (): void {
                                            $chatId = $this->form->getState()['telegram_chat_id'] ?? '';
                                            if ($chatId === '') {
                                                Notification::make()
                                                    ->title('Chat ID Free vacío')
                                                    ->body('Rellena Chat ID Free y guarda antes de probar.')
                                                    ->danger()
                                                    ->send();
                                                return;
                                            }
                                            $result = app(NotificadorTelegram::class)->enviarMensajePruebaAChat((string) $chatId);
                                            if ($result['ok']) {
                                                Notification::make()
                                                    ->title('Mensaje enviado')
                                                    ->body('Revisa el canal de Telegram (Chat ID Free).')
                                                    ->success()
                                                    ->send();
                                            } else {
                                                Notification::make()
                                                    ->title('Error al enviar')
                                                    ->body($result['error'] ?? 'Error desconocido')
                                                    ->danger()
                                                    ->send();
                                            }
                                        }),
                                ]),
                            ])
                            ->columns(1),

                        Forms\Components\Tabs\Tab::make('Afiliados')
                            ->icon('heroicon-o-link')
                            ->schema([
                                Forms\Components\TextInput::make('ml_affiliate_id')
                                    ->label('Mercado Libre Afiliado ID')
                                    ->placeholder('187001804')
                                    ->default('187001804'),
                                Forms\Components\TextInput::make('amazon_tag')
                                    ->label('Amazon Tag')
                                    ->placeholder('micosmtics-20')
                                    ->default('micosmtics-20'),
                                Forms\Components\TextInput::make('admitad_base_url')
                                    ->label('Admitad Base URL')
                                    ->placeholder('https://api.admitad.com')
                                    ->url(),
                                Forms\Components\TextInput::make('admitad_publisher_id')
                                    ->label('Admitad Publisher ID')
                                    ->placeholder('Website ID o Publisher ID'),
                            ])
                            ->columns(1),

                        Forms\Components\Tabs\Tab::make('APIs (Mercado Pago/Libre)')
                            ->icon('heroicon-o-key')
                            ->schema([
                                Forms\Components\TextInput::make('ml_app_id')
                                    ->label('ML App ID')
                                    ->placeholder('Dejar vacío para usar .env'),
                                Forms\Components\TextInput::make('ml_secret_key')
                                    ->label('ML Secret Key')
                                    ->password()
                                    ->revealable()
                                    ->placeholder('Dejar vacío para usar .env'),
                                Forms\Components\Placeholder::make('ml_estado_conexion')
                                    ->label('Estado de conexión')
                                    ->content(fn (): string => $this->estadoConexionMl()),
                            ])
                            ->columns(1),

                        Forms\Components\Tabs\Tab::make('Proxy y Rastreo')
                            ->icon('heroicon-o-globe-alt')
                            ->schema([
                                Forms\Components\Toggle::make('proxy_habilitado')
                                    ->label('Activar proxy global')
                                    ->helperText('Si está desactivado, el rastreo no usará proxy.')
                                    ->default(true),
                                Forms\Components\TextInput::make('proxy_url')
                                    ->label('URL del Proxy')
                                    ->placeholder('http://usuario:contraseña@host:puerto')
                                    ->helperText('Prioridad sobre .env. Dejar vacío para usar PROXY_URL del .env.')
                                    ->url(),
                                Forms\Components\Section::make('Diagnóstico de Proxy')
                                    ->description('Comprueba que el proxy esté activo haciendo una petición a internet y mostrando la IP detectada (si es de México, el proxy funciona correctamente).')
                                    ->schema([
                                        Forms\Components\Actions::make([
                                            Forms\Components\Actions\Action::make('verificar_proxy')
                                                ->label('Verificar Proxy')
                                                ->icon('heroicon-o-signal')
                                                ->action(function (): void {
                                                    $r = app(DiagnosticoConexionService::class)->probarProxy();
                                                    $this->proxyVerificadoResultado = $r['ok']
                                                        ? '✅ ' . $r['mensaje']
                                                        : '❌ ' . $r['mensaje'];
                                                }),
                                        ]),
                                        Forms\Components\Placeholder::make('resultado_verificar_proxy')
                                            ->label('IP resultante')
                                            ->content(fn (): string => $this->proxyVerificadoResultado ?? '— Pulsa «Verificar Proxy» para comprobar. —'),
                                    ])
                                    ->columns(1),
                            ])
                            ->columns(1),

                        Forms\Components\Tabs\Tab::make('Diagnóstico de Conexión')
                            ->icon('heroicon-o-signal')
                            ->schema([
                                Forms\Components\Section::make('Probar API Mercado Libre')
                                    ->description('Comprueba que el Access Token guardado sea válido y obtiene el usuario asociado.')
                                    ->schema([
                                        Forms\Components\Actions::make([
                                            Forms\Components\Actions\Action::make('probar_ml')
                                                ->label('Probar API Mercado Libre')
                                                ->icon('heroicon-o-check-circle')
                                                ->action(function (): void {
                                                    $r = app(DiagnosticoConexionService::class)->probarApiMercadoLibre();
                                                    $this->diagnosticoMlModoScraping = ! ($r['ok'] ?? false);
                                                    $this->diagnosticoMlResultado = $r['ok']
                                                        ? $r['mensaje']
                                                        : '❌ ' . $r['mensaje'];
                                                }),
                                        ]),
                                        Forms\Components\Placeholder::make('aviso_modo_scraping_ml')
                                            ->label('')
                                            ->content('⚠️ API no certificada: Operando en modo scraping con enlaces largos (Seguro).')
                                            ->visible(fn (): bool => $this->diagnosticoMlModoScraping)
                                            ->extraAttributes(['class' => 'rounded-lg bg-amber-50 dark:bg-amber-900/20 p-4 text-amber-800 dark:text-amber-200 border border-amber-200 dark:border-amber-800']),
                                        Forms\Components\Placeholder::make('resultado_ml')
                                            ->label('Resultado')
                                            ->content(fn (): string => $this->diagnosticoMlResultado ?? '— Pulsa el botón para probar. —'),
                                    ])
                                    ->columns(1),

                                Forms\Components\Section::make('Probar Proxy')
                                    ->description('Hace una petición a internet usando el proxy configurado y muestra la IP detectada (para confirmar que el proxy está activo).')
                                    ->schema([
                                        Forms\Components\Actions::make([
                                            Forms\Components\Actions\Action::make('probar_proxy')
                                                ->label('Probar Proxy')
                                                ->icon('heroicon-o-globe-alt')
                                                ->action(function (): void {
                                                    $r = app(DiagnosticoConexionService::class)->probarProxy();
                                                    $this->diagnosticoProxyResultado = $r['ok']
                                                        ? '✅ ' . $r['mensaje']
                                                        : '❌ ' . $r['mensaje'];
                                                }),
                                        ]),
                                        Forms\Components\Placeholder::make('resultado_proxy')
                                            ->label('Resultado')
                                            ->content(fn (): string => $this->diagnosticoProxyResultado ?? '— Pulsa el botón para probar. —'),
                                    ])
                                    ->columns(1),

                                Forms\Components\Section::make('Simulador de Enlace')
                                    ->description('Pega un link de producto y verás cómo quedaría ya monetizado (tag Amazon, micosmtics ML o Admitad).')
                                    ->schema([
                                        Forms\Components\TextInput::make('simulador_url')
                                            ->label('URL del producto')
                                            ->placeholder('https://www.amazon.com.mx/dp/... o https://articulo.mercadolibre.com.mx/...')
                                            ->url()
                                            ->live(onBlur: true),
                                        Forms\Components\Actions::make([
                                            Forms\Components\Actions\Action::make('simular_enlace')
                                                ->label('Simular enlace monetizado')
                                                ->icon('heroicon-o-link')
                                                ->action(function (): void {
                                                    $url = trim((string) ($this->form->getState()['simulador_url'] ?? ''));
                                                    $r = app(DiagnosticoConexionService::class)->simularEnlace($url);
                                                    $this->diagnosticoSimuladorTipo = $r['tipo'] ?? '';
                                                    if ($r['ok']) {
                                                        $this->diagnosticoSimuladorResultado = $r['url'];
                                                    } else {
                                                        $this->diagnosticoSimuladorResultado = '❌ ' . ($r['mensaje'] ?? 'Error');
                                                    }
                                                }),
                                        ]),
                                        Forms\Components\Placeholder::make('resultado_simulador_tipo')
                                            ->label('Tipo aplicado')
                                            ->content(fn (): string => $this->diagnosticoSimuladorTipo ?? '—'),
                                        Forms\Components\Placeholder::make('resultado_simulador_url')
                                            ->label('URL resultante')
                                            ->content(fn (): string => $this->diagnosticoSimuladorResultado ?? '— Pega una URL y pulsa Simular. —'),
                                    ])
                                    ->columns(1),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private function estadoConexionMl(): string
    {
        $access = Configuracion::obtener(Configuracion::CLAVE_ML_ACCESS_TOKEN);
        $expiresAt = Configuracion::obtener(Configuracion::CLAVE_ML_EXPIRES_AT);
        if ($access === null || $access === '') {
            return 'Sin token (conecta vía /mercado-libre/login si usas API).';
        }
        if ($expiresAt === null || $expiresAt === '') {
            return 'Token guardado (fecha de expiración no definida).';
        }
        $ts = (int) $expiresAt;
        if ($ts > time()) {
            return 'Conectado (expira ' . date('d/m/Y H:i', $ts) . ').';
        }
        $valido = MercadoLibreTokenService::obtenerAccessTokenValido();
        return $valido !== null ? 'Conectado (renovado automáticamente).' : 'Expirado (renueva desde /mercado-libre/login).';
    }

    /**
     * @return array<Action | \Filament\Actions\ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('guardar')
                ->label('Guardar configuración')
                ->submit('guardar'),
        ];
    }

    public function guardar(): void
    {
        $state = $this->form->getState();
        Configuracion::guardar(Configuracion::CLAVE_TELEGRAM_TOKEN, (string) ($state['telegram_token'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_TELEGRAM_CHAT_ID, (string) ($state['telegram_chat_id'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_TELEGRAM_CHAT_ID_PREMIUM, (string) ($state['telegram_chat_id_premium'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_ML_AFFILIATE_ID, (string) ($state['ml_affiliate_id'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_AMAZON_TAG, (string) ($state['amazon_tag'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_ADMITAD_BASE_URL, (string) ($state['admitad_base_url'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_ADMITAD_PUBLISHER_ID, (string) ($state['admitad_publisher_id'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_ML_APP_ID, (string) ($state['ml_app_id'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_ML_SECRET_KEY, (string) ($state['ml_secret_key'] ?? ''));
        Configuracion::guardar(Configuracion::CLAVE_PROXY_HABILITADO, (bool) ($state['proxy_habilitado'] ?? true));
        Configuracion::guardar(Configuracion::CLAVE_PROXY_URL, (string) ($state['proxy_url'] ?? ''));
        Configuracion::limpiarCache();
        Notification::make()
            ->title('Configuración guardada')
            ->body('Los valores tienen prioridad sobre el .env. Reinicia el worker si está corriendo: sudo supervisorctl restart mayoreo-worker:*')
            ->success()
            ->send();
    }

    public function getFormStatePath(): ?string
    {
        return 'data';
    }
}
