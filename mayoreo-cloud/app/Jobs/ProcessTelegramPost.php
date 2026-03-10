<?php

namespace App\Jobs;

use App\Models\Producto;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessTelegramPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public int $backoff = 35;

    public function __construct(
        public Producto $producto
    ) {}

    public function handle(TelegramService $telegram): void
    {
        $ok = $telegram->sendOffer($this->producto);

        if (! $ok) {
            // Si Telegram devolvió retry_after, esperar ese tiempo antes del siguiente intento
            $retryAfter = $telegram->getLastRetryAfter();
            if ($retryAfter > 0) {
                $this->release($retryAfter + 2);
                return;
            }
            // Otro error: reintentar con backoff estándar
            $this->release($this->backoff);
        }
    }
}
