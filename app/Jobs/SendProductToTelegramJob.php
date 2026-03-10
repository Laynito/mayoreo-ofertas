<?php

namespace App\Jobs;

use App\Models\Producto;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendProductToTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Producto $producto
    ) {}

    public function handle(TelegramService $telegram): void
    {
        $telegram->sendOffer($this->producto);
    }
}
