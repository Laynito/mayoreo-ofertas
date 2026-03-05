<?php

namespace App\Exceptions;

use Exception;

/**
 * Lanzada cuando la API de Telegram devuelve 429 Too Many Requests.
 * El Job puede capturarla y usar release($segundos) para reintentar más tarde.
 */
class TelegramRateLimitException extends Exception
{
}
