<?php

namespace App\Filament\Pages;

use App\Models\Configuracion;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;

class ConfiguracionNotificaciones extends Page
{
    use InteractsWithFormActions;
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Configuración';

    protected static ?string $title = 'Configuración de notificaciones';

    protected static ?string $navigationGroup = 'Sistema';

    protected static string $view = 'filament.pages.configuracion-notificaciones';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'notificaciones_porcentaje_minimo' => Configuracion::porcentajeMinimoNotificacion(),
            'notificaciones_requiere_descuento_adicional' => Configuracion::requiereDescuentoAdicional(),
            'notificaciones_porcentaje_premium' => Configuracion::porcentajeMinimoParaPremium(),
            'enviar_imagenes' => Configuracion::enviarImagenes(),
        ]);
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Section::make('Notificaciones por Telegram')
                ->description('Criterios para enviar ofertas al chat de Telegram al rastrear tiendas.')
                ->schema([
                    Forms\Components\TextInput::make('notificaciones_porcentaje_minimo')
                        ->label('Porcentaje mínimo de ahorro (%)')
                        ->helperText('Solo se notifican ofertas con al menos este % de descuento (ej: 10 = 10%).')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(10)
                        ->required(),
                    Forms\Components\Toggle::make('notificaciones_requiere_descuento_adicional')
                        ->label('Solo productos con descuento adicional')
                        ->helperText('Si está activo, solo se notifican productos marcados como "permite descuento adicional".')
                        ->default(true),
                    Forms\Components\TextInput::make('notificaciones_porcentaje_premium')
                        ->label('Porcentaje mínimo para canal Premium (%)')
                        ->helperText('Ofertas ≥ este % → Premium; entre mínimo y (este - 1)% → Free. Ej: 20 = ≥20% Premium, 10–19% Free.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(20)
                        ->required(),
                    Forms\Components\Toggle::make('enviar_imagenes')
                        ->label('Enviar imágenes con las ofertas')
                        ->helperText('Si está desactivado, se envía solo texto (ahorra recursos y evita errores 400 con CDN de Coppel).')
                        ->default(true),
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
                ->label('Guardar configuración')
                ->submit('guardar'),
        ];
    }

    public function guardar(): void
    {
        $state = $this->form->getState();
        Configuracion::guardar(Configuracion::CLAVE_PORCENTAJE_MINIMO, (int) ($state['notificaciones_porcentaje_minimo'] ?? 10));
        Configuracion::guardar(Configuracion::CLAVE_REQUIERE_DESCUENTO_ADICIONAL, (bool) ($state['notificaciones_requiere_descuento_adicional'] ?? true));
        Configuracion::guardar(Configuracion::CLAVE_PORCENTAJE_PREMIUM, (int) ($state['notificaciones_porcentaje_premium'] ?? 20));
        Configuracion::guardar(Configuracion::CLAVE_ENVIAR_IMAGENES, (bool) ($state['enviar_imagenes'] ?? true));

        Configuracion::limpiarCache();

        Notification::make()
            ->title('Configuración guardada')
            ->success()
            ->send();
    }
}
