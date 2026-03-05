<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Estado de salud de cada motor de rastreo (para panel Filament).
 * Permite ver bloqueos (403, "Verifica tu identidad") y desactivar temporalmente un motor.
 */
class EstadoMotor extends Model
{
    protected $table = 'estado_motores';

    protected $fillable = [
        'nombre_tienda',
        'estado',
        'ultimo_error',
        'ultima_actualizacion',
    ];

    protected $casts = [
        'ultima_actualizacion' => 'datetime',
    ];

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_BLOQUEADO = 'bloqueado';
    public const ESTADO_FALLO_TEMPORAL = 'fallo_temporal';

    public function estaBloqueado(): bool
    {
        return $this->estado === self::ESTADO_BLOQUEADO;
    }

    public function estaActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }
}
