<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoPrecioHistorial extends Model
{
    protected $table = 'producto_precio_historial';

    public $timestamps = false;

    protected $fillable = ['producto_id', 'precio_actual', 'fecha'];

    protected $casts = [
        'precio_actual' => 'decimal:2',
        'fecha' => 'date',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
