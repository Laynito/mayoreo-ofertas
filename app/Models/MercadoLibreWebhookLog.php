<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de cada POST recibido en el webhook de Mercado Libre (topic, resource, sent_time).
 */
class MercadoLibreWebhookLog extends Model
{
    protected $table = 'mercado_libre_webhooks_log';

    public $timestamps = false;

    protected $fillable = [
        'topic',
        'resource',
        'sent_time',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }
}
