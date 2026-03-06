<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificacionLog extends Model
{
    protected $table = 'notificacion_logs';

    protected $fillable = [
        'producto_id',
        'tienda',
        'chat_id',
        'estado',
        'mensaje_error',
        'enlace_generado',
        'origen_rastreo',
    ];

    public const ESTADO_ENVIADO = 'enviado';
    public const ESTADO_FALLIDO = 'fallido';
    public const ESTADO_OMITIDO = 'omitido';

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * Registra un intento de notificación (desde NotificadorTelegram o EnviarOfertaTelegramJob).
     *
     * @param  string|null  $origenRastreo  'API' o 'Scraping' según origen del rastreo (Mercado Libre).
     */
    public static function registrar(
        ?int $productoId,
        ?string $tienda,
        ?string $chatId,
        string $estado,
        ?string $mensajeError = null,
        ?string $enlaceGenerado = null,
        ?string $origenRastreo = null
    ): self {
        return self::query()->create([
            'producto_id' => $productoId,
            'tienda' => $tienda,
            'chat_id' => $chatId,
            'estado' => $estado,
            'mensaje_error' => $mensajeError,
            'enlace_generado' => $enlaceGenerado,
            'origen_rastreo' => $origenRastreo,
        ]);
    }
}
