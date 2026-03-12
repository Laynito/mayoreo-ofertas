<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AfiliadoClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'producto_id',
        'marketplace_slug',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
