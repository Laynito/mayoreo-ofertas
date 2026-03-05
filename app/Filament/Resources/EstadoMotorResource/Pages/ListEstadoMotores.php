<?php

namespace App\Filament\Resources\EstadoMotorResource\Pages;

use App\Filament\Resources\EstadoMotorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEstadoMotores extends ListRecords
{
    protected static string $resource = EstadoMotorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Sin creación manual; los registros se crean al registrar fallos desde los motores.
        ];
    }
}
