<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\ProductoPrecioHistorial;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $baseUrl;

    private string $chatId;

    public function __construct()
    {
        $token = config('services.telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$token}";
        $this->chatId = config('services.telegram.chat_id_free');
    }

    /**
     * Envía una oferta al canal usando sendPhoto y el formato acordado.
     * El enlace "Ver Oferta" usa siempre la URL canónica de producto + matt_tool, matt_word, affid (ML_AFFID).
     */
    public function sendOffer(Producto $producto): bool
    {
        $urlAfiliado = app(AffiliateService::class)->getCanonicalAffiliateLink($producto->url_producto);
        $precioOriginal = $producto->precio_original
            ? number_format((float) $producto->precio_original, 2)
            : '—';

        $precioAyer = ProductoPrecioHistorial::query()
            ->where('producto_id', $producto->id)
            ->where('fecha', Carbon::yesterday()->toDateString())
            ->value('precio_actual');

        $caption = $this->buildCaption(
            $producto->nombre,
            (int) $producto->descuento,
            number_format((float) $producto->precio_actual, 2),
            $precioOriginal,
            $urlAfiliado,
            $precioAyer !== null ? number_format((float) $precioAyer, 2) : null
        );

        // sendPhoto solo si la URL de imagen es válida (http/https, host público)
        $photo = $this->isValidPhotoUrl($producto->url_imagen) ? $producto->url_imagen : null;

        try {
            if ($photo) {
                $response = Http::post("{$this->baseUrl}/sendPhoto", [
                    'chat_id' => $this->chatId,
                    'photo' => $photo,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

                // Si Telegram rechaza la URL (ej. "Host is invalid"), enviar solo texto
                if (! $response->successful() && $this->isInvalidPhotoError($response->body())) {
                    $response = Http::post("{$this->baseUrl}/sendMessage", [
                        'chat_id' => $this->chatId,
                        'text' => $caption,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                }
            } else {
                $response = Http::post("{$this->baseUrl}/sendMessage", [
                    'chat_id' => $this->chatId,
                    'text' => $caption,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Error Telegram: ' . $e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            Log::warning('TelegramService: fallo envío', [
                'producto_id' => $producto->id,
                'response' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Envía un mensaje de texto al chat configurado (para pruebas).
     */
    public function sendText(string $text): bool
    {
        try {
            $response = Http::post("{$this->baseUrl}/sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error Telegram: ' . $e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            Log::warning('TelegramService: fallo sendText', [
                'response' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    private function buildCaption(
        string $nombre,
        int $descuento,
        string $precioActual,
        string $precioOriginal,
        string $urlAfiliado,
        ?string $precioAyer = null
    ): string {
        if ($descuento >= 70) {
            $tituloEmotivo = "🚨 ¡OFERTA DE LOCURA! ({$descuento}%) 🚨";
        } elseif ($descuento >= 50) {
            $tituloEmotivo = "🔥 ¡SÚPER PRECIO! ({$descuento}%) 🔥";
        } elseif ($descuento >= 30) {
            $tituloEmotivo = "💸 ¡BUEN DESCUENTO! ({$descuento}%) 💸";
        } else {
            $tituloEmotivo = "✨ ¡NUEVA OFERTA! ({$descuento}%) ✨";
        }

        $nombre = mb_substr($nombre, 0, 200);
        $text = $tituloEmotivo . "\n\n";
        $text .= "📦 <b>" . $this->escapeHtml($nombre) . "</b>\n";
        $text .= "💰 De: <s>\${$precioOriginal}</s>\n";
        if ($precioAyer !== null) {
            $text .= "📅 Ayer: \${$precioAyer}\n";
        }
        $text .= "✅ <b>Hoy: \${$precioActual}</b>\n\n";
        $text .= "🔗 <a href=\"" . $this->escapeHtml($urlAfiliado) . "\">Ver en Mercado Libre</a>";

        return mb_substr($text, 0, 1024);
    }

    private function escapeHtml(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    /**
     * Comprueba si la URL es válida para Telegram (http/https, host público).
     */
    private function isValidPhotoUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }
        $parsed = parse_url($url);
        if (! isset($parsed['scheme'], $parsed['host']) || ! in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower($parsed['host']);
        if ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.local')) {
            return false;
        }

        return true;
    }

    /**
     * Indica si el cuerpo de la respuesta de Telegram es un error de URL de foto inválida.
     */
    private function isInvalidPhotoError(string $body): bool
    {
        return str_contains($body, 'invalid file HTTP URL')
            || str_contains($body, 'Host is invalid')
            || str_contains($body, 'wrong file identifier');
    }
}
