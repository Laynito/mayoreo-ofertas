<?php

namespace App\Filament\Resources\EstadoMotorResource\Pages;

use App\Filament\Resources\EstadoMotorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEstadoMotor extends EditRecord
{
    protected static string $resource = EstadoMotorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
