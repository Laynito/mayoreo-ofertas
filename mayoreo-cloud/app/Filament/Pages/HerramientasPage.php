<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

class HerramientasPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Herramientas';

    protected static ?string $title = 'Herramientas del scraper y cola';

    protected static ?string $navigationGroup = 'Sistema';

    protected static string $view = 'filament.pages.herramientas-page';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_scraper')
                ->label('Ejecutar scraper ahora')
                ->icon('heroicon-o-play')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Ejecutar ciclo completo')
                ->modalDescription('Se lanzará el scraper contra la web de ofertas y luego la sincronización de afiliados y Telegram. El proceso corre en segundo plano.')
                ->action(function (): void {
                    if (Storage::exists('scraper_status.json')) {
                        $json = Storage::get('scraper_status.json');
                        $decoded = json_decode($json, true);
                        if (is_array($decoded) && ($decoded['status'] ?? '') === 'running') {
                            Notification::make()
                                ->title('Ya hay una ejecución en curso')
                                ->body('El scraper está corriendo. Espera a que termine (recarga la página para ver "Completado") o revisa storage/logs/laravel.log. No se lanzó otro proceso.')
                                ->warning()
                                ->send();
                            return;
                        }
                    }
                    $command = [
                        'php',
                        base_path('artisan'),
                        'app:run-scraper',
                    ];
                    $process = new SymfonyProcess($command, base_path(), null, null, null);
                    $process->start();
                    Notification::make()
                        ->title('Scraper iniciado')
                        ->body('El ciclo se está ejecutando en segundo plano. Para comprobar el estado, recarga esta página y mira la sección "Estado del scraper" más abajo, o revisa storage/logs/laravel.log.')
                        ->success()
                        ->send();
                }),
            Action::make('queue_failed')
                ->label('Ver cola fallida')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->action(function (): void {
                    Artisan::call('queue:failed');
                    $output = trim(Artisan::output());
                    if ($output === '') {
                        $output = 'No hay jobs fallidos en la cola.';
                    } else {
                        $output = strlen($output) > 1500 ? substr($output, 0, 1500) . "\n…" : $output;
                    }
                    Notification::make()
                        ->title('Cola fallida')
                        ->body($output)
                        ->persistent()
                        ->send();
                }),
            Action::make('limpiar_ofertas')
                ->label('Vaciar tabla de productos')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Vaciar tabla de productos por completo')
                ->modalDescription('Se vaciarán las tablas productos y producto_precio_historial (truncate). Los IDs se reiniciarán. Esta acción no se puede deshacer. ¿Continuar?')
                ->action(function (): void {
                    Artisan::call('app:limpiar-ofertas');
                    Notification::make()
                        ->title('Tablas vaciadas')
                        ->body('Productos e historial de precios han sido vaciados por completo. Los IDs se reiniciaron.')
                        ->success()
                        ->send();
                }),
            Action::make('clean_logs')
                ->label('Limpiar logs')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Limpiar logs y captura de error')
                ->modalDescription('Se eliminarán storage/logs/*.log y error.png (si existen). ¿Continuar?')
                ->action(function (): void {
                    $deleted = [];
                    $logsPath = storage_path('logs');
                    if (is_dir($logsPath)) {
                        foreach (File::glob($logsPath . '/*.log') as $file) {
                            File::delete($file);
                            $deleted[] = basename($file);
                        }
                    }
                    $errorPng = base_path('error.png');
                    if (File::exists($errorPng)) {
                        File::delete($errorPng);
                        $deleted[] = 'error.png';
                    }
                    $msg = empty($deleted)
                        ? 'No había archivos que eliminar.'
                        : 'Eliminados: ' . implode(', ', $deleted);
                    Notification::make()
                        ->title('Logs limpiados')
                        ->body($msg)
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getViewData(): array
    {
        $data = parent::getViewData();
        $data['scraperStatus'] = null;
        if (Storage::exists('scraper_status.json')) {
            $json = Storage::get('scraper_status.json');
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $data['scraperStatus'] = $decoded;
            }
        }
        return $data;
    }
}
