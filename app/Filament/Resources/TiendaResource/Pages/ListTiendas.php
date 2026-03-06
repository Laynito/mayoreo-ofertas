<?php

namespace App\Filament\Resources\TiendaResource\Pages;

use App\Fabrica\RastreadorFabrica;
use App\Filament\Resources\TiendaResource;
use App\Jobs\EjecutarRastreoTiendaJob;
use App\Models\Tienda;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTiendas extends ListRecords
{
    protected static string $resource = TiendaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('rastrear_todas')
                ->label('Rastrear todas las tiendas')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->action(function (): void {
                    $tiendas = Tienda::query()->where('activo', true)->orderBy('nombre')->get();
                    if ($tiendas->isEmpty()) {
                        \Filament\Notifications\Notification::make()
                            ->title('Sin tiendas activas')
                            ->body('No hay tiendas con rastreo activo. Activa al menos una en la tabla.')
                            ->warning()
                            ->send();
                        return;
                    }
                    $delay = 0;
                    foreach ($tiendas as $tienda) {
                        EjecutarRastreoTiendaJob::dispatch($tienda->nombre)
                            ->onQueue('default')
                            ->delay(now()->addSeconds($delay));
                        $delay += 45;
                    }
                    \Filament\Notifications\Notification::make()
                        ->title('Rastreo encolado')
                        ->body('Se han encolado ' . $tiendas->count() . ' tiendas. Se ejecutarán con ' . 45 . ' s de separación. Revisa el worker y Telegram.')
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalHeading('Rastrear todas las tiendas activas')
                ->modalDescription('Se encolará el rastreo de cada tienda activa (con 45 s entre cada una para no saturar). Los resultados y posibles errores llegarán por Telegram y al Log de notificaciones.'),
            Actions\Action::make('importar_configuradas')
                ->label('Importar tiendas configuradas')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (): void {
                    $listado = RastreadorFabrica::listadoParaSeeder();
                    $count = 0;
                    foreach ($listado as $item) {
                        Tienda::updateOrCreate(
                            ['nombre' => $item['nombre']],
                            [
                                'clase_motor' => $item['clase_motor'],
                                'activo' => true,
                            ]
                        );
                        $count++;
                    }
                    \Filament\Notifications\Notification::make()
                        ->title('Tiendas actualizadas')
                        ->body($count === 0 ? 'No hay tiendas en el sistema de motores.' : "Se han importado/actualizado {$count} tiendas desde los motores configurados.")
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalHeading('Importar tiendas desde motores')
                ->modalDescription('Añade o actualiza en la tabla las tiendas que ya tienen motor en el código (Amazon, Mercado Libre, Coppel, etc.). No borra ni cambia la URL/selector/notas de las existentes.'),
            Actions\CreateAction::make(),
        ];
    }
}
