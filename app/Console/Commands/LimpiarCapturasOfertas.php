<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Borra capturas de pantalla antiguas (Browsershot) para no saturar el servidor.
 * Elimina archivos .png en storage/app/public/capturas/ con más de 24 horas.
 */
class LimpiarCapturasOfertas extends Command
{
    protected $signature = 'ofertas:limpiar-capturas';

    protected $description = 'Borra capturas de pantalla (.png) con más de 24 horas en storage/app/public/capturas/';

    /** Antigüedad máxima en segundos (24 horas). */
    private const HORAS_MAXIMAS = 24;

    public function handle(): int
    {
        $carpeta = storage_path('app/public/capturas');

        if (! File::isDirectory($carpeta)) {
            $this->info("La carpeta {$carpeta} no existe. Nada que limpiar.");

            return self::SUCCESS;
        }

        $limite = time() - (self::HORAS_MAXIMAS * 3600);
        $archivos = File::glob($carpeta . '/*.png');
        $borrados = 0;

        foreach ($archivos as $ruta) {
            if (filemtime($ruta) < $limite) {
                if (@unlink($ruta)) {
                    $borrados++;
                }
            }
        }

        $this->info("Capturas eliminadas: {$borrados} (archivos .png con más de " . self::HORAS_MAXIMAS . ' horas).');

        return self::SUCCESS;
    }
}
