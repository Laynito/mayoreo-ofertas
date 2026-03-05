<?php

namespace App\Services;

use App\Models\EstadoMotor;
use Illuminate\Support\Facades\Log;

/**
 * Registra fallos de extracción por motor y actualiza estado (bloqueado/activo) para el panel Filament.
 */
class EstadoMotorService
{
    /**
     * Registra un fallo de extracción. Si el error indica bloqueo (403, "Verifica tu identidad"),
     * marca el motor como bloqueado.
     */
    public function registrarFallo(string $nombreTienda, string $error, ?int $status = null): void
    {
        Log::warning('Fallo en el motor: ' . $nombreTienda . '. Detalle: ' . $error, [
            'motor' => $nombreTienda,
            'status' => $status,
        ]);

        $estado = $this->interpretarEstado($error, $status);
        $this->actualizarEstadoMotor($nombreTienda, $estado, $error);
    }

    /**
     * Actualiza el estado del motor en BD (activo, bloqueado, fallo_temporal).
     */
    public function actualizarEstadoMotor(string $nombreTienda, string $estado, ?string $ultimoError = null): void
    {
        EstadoMotor::updateOrCreate(
            ['nombre_tienda' => $nombreTienda],
            [
                'estado' => $estado,
                'ultimo_error' => $ultimoError !== null ? mb_substr($ultimoError, 0, 2000) : null,
                'ultima_actualizacion' => now(),
            ]
        );
    }

    /**
     * Indica si el motor está marcado como bloqueado (no rastrear hasta reactivación manual).
     */
    public function estaBloqueado(string $nombreTienda): bool
    {
        $registro = EstadoMotor::where('nombre_tienda', $nombreTienda)->first();

        return $registro !== null && $registro->estado === EstadoMotor::ESTADO_BLOQUEADO;
    }

    /**
     * Reactiva un motor (por ejemplo desde Filament).
     */
    public function reactivar(string $nombreTienda): void
    {
        $this->actualizarEstadoMotor($nombreTienda, EstadoMotor::ESTADO_ACTIVO, null);
    }

    private function interpretarEstado(string $error, ?int $status): string
    {
        if ($status === 403 || str_contains($error, '403') || str_contains($error, 'Verifica tu identidad')) {
            return EstadoMotor::ESTADO_BLOQUEADO;
        }
        if ($status === 429 || str_contains($error, '429') || str_contains($error, 'timeout')) {
            return EstadoMotor::ESTADO_FALLO_TEMPORAL;
        }

        return EstadoMotor::ESTADO_FALLO_TEMPORAL;
    }
}
