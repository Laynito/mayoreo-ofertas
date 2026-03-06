<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RedirectLink extends Model
{
    protected $table = 'redirect_links';

    protected $fillable = [
        'codigo',
        'url_destino',
        'subid',
    ];

    protected function casts(): array
    {
        return [
            //
        ];
    }

    public function clics(): HasMany
    {
        return $this->hasMany(Click::class, 'redirect_link_id');
    }
}
