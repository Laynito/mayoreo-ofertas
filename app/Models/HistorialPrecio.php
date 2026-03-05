<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico de precios por producto (equivalente a "HistoricoPrecio").
 * Cada cambio de precio del rastreo genera un registro aquí; se usa para detectar
 * bajadas históricas (ej. ≥20% vs el registro anterior) y notificar con captura de pantalla.
 */
class HistorialPrecio extends Model
{
    use HasFactory;

    protected $table = 'historial_precios';

    protected $fillable = [
        'producto_id',
        'precio_original',
        'precio_oferta',
        'porcentaje_ahorro',
        'registrado_en',
    ];

    protected function casts(): array
    {
        return [
            'precio_original' => 'decimal:2',
            'precio_oferta' => 'decimal:2',
            'porcentaje_ahorro' => 'decimal:2',
            'registrado_en' => 'datetime',
        ];
    }

    /**
     * Producto al que pertenece este registro de historial.
     */
    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
