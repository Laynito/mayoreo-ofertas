<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Click extends Model
{
    protected $table = 'clics';

    protected $fillable = [
        'redirect_link_id',
        'ip',
        'user_agent',
        'clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
        ];
    }

    public function redirectLink(): BelongsTo
    {
        return $this->belongsTo(RedirectLink::class);
    }
}
