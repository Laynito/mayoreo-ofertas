<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de mensajes de oferta enviados a Telegram (chat_id + message_id).
 * Se usa para borrarlos con deleteMessage después de 24 h y mantener el canal limpio.
 */
class TelegramMensajeOferta extends Model
{
    protected $table = 'telegram_mensajes_oferta';

    public $timestamps = true;

    protected $fillable = [
        'chat_id',
        'message_id',
        'producto_id',
        'enviado_at',
    ];

    protected function casts(): array
    {
        return [
            'enviado_at' => 'datetime',
            'message_id' => 'integer',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
