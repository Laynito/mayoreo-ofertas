<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Producto;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Notificador de ofertas vía Telegram (Free y Premium).
 * Segmentación: ≥20% → canal Premium; 10–19% → canal Free.
 * Si la oferta es Premium, se envía además un teaser al canal Free (marketing de interrupción).
 * Toggle enviar_imagenes: si está desactivado, se envía solo texto (evita 400 de Coppel).
 */
class NotificadorTelegram
{
    /**
     * Resuelve el chat_id según el % de ahorro: ≥ umbral Premium → TELEGRAM_CHAT_ID_PREMIUM, si no → TELEGRAM_CHAT_ID_FREE.
     * Fallback a services.telegram.chat_id si free/premium no están definidos.
     */
    private function chatIdParaOferta(float $porcentajeAhorro): ?string
    {
        $umbralPremium = Configuracion::porcentajeMinimoParaPremium();
        $esPremium = $porcentajeAhorro >= $umbralPremium;

        $chatId = $esPremium
            ? config('services.telegram.chat_id_premium')
            : config('services.telegram.chat_id_free');

        if ($chatId !== null && $chatId !== '') {
            return (string) $chatId;
        }

        return config('services.telegram.chat_id') ? (string) config('services.telegram.chat_id') : null;
    }

    /**
     * Indica si la oferta se considera Premium (≥ umbral configurado, por defecto 20%).
     */
    private function esOfertaPremium(float $porcentajeAhorro): bool
    {
        return $porcentajeAhorro >= Configuracion::porcentajeMinimoParaPremium();
    }

    /**
     * Notifica una oferta al canal que corresponda (Free 10–19%, Premium ≥20%).
     * Si enviar_imagenes está desactivado, envía solo texto. Si la oferta es Premium, envía teaser al canal Free.
     */
    public function notificarOferta(Producto $producto): void
    {
        $porcentaje = $producto->porcentaje_ahorro !== null ? (float) $producto->porcentaje_ahorro : 0;
        $minimo = Configuracion::porcentajeMinimoNotificacion();
        $requiereAdicional = Configuracion::requiereDescuentoAdicional();

        if ($porcentaje < $minimo || ($requiereAdicional && ! $producto->permite_descuento_adicional)) {
            return;
        }

        if (! Configuracion::enviarImagenes()) {
            $this->enviarOfertaSoloTexto($producto);

            return;
        }

        $token = config('services.telegram.token');
        $chatId = $this->chatIdParaOferta($porcentaje);

        if (empty($token) || empty($chatId)) {
            Log::debug('NotificadorTelegram: TELEGRAM_BOT_TOKEN o chat_id (FREE/PREMIUM/CHAT_ID) no configurados.');

            return;
        }

        $esPremium = $this->esOfertaPremium($porcentaje);
        Log::info('NotificadorTelegram: oferta enviada a canal ' . ($esPremium ? 'Premium' : 'Free'), [
            'producto_id' => $producto->id,
            'sku_tienda' => $producto->sku_tienda,
            'porcentaje_ahorro' => $porcentaje,
        ]);

        if ($esPremium) {
            $this->enviarTeaserOfertaBombaAlCanalFree($porcentaje, $producto->categoria_origen ?? null);
        }

        $urlAfiliado = $producto->url_afiliado_completa ?? $producto->url_original;
        $caption = $this->construirCaption($producto, $porcentaje, $esPremium, false);
        $urlApi = "https://api.telegram.org/bot{$token}/sendPhoto";

        $payload = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];
        if (! empty($urlAfiliado)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => [
                    [['text' => 'Ver en Tienda', 'url' => $urlAfiliado]],
                ],
            ]);
        }

        if (empty($producto->imagen_url)) {
            $this->enviarMensajeSinFoto($token, $chatId, $caption, $urlAfiliado);

            return;
        }

        $payload['photo'] = $producto->imagen_url;

        try {
            $response = Http::withOptions(['verify' => false])
                ->timeout(10)
                ->connectTimeout(5)
                ->asForm()
                ->post($urlApi, $payload);

            if (! $response->successful()) {
                throw new \RuntimeException(
                    'sendPhoto failed: ' . $response->status() . ' ' . $response->body()
                );
            }
        } catch (\Throwable $e) {
            Log::warning('NotificadorTelegram: fallo sendPhoto, reenviando solo texto', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
                'error' => $e->getMessage(),
            ]);
            $captionFallback = $caption . "\n\n🖼️ (Imagen no disponible temporalmente)";
            $this->enviarMensajeSinFoto($token, $chatId, $captionFallback, $urlAfiliado);
        }
    }

    /**
     * Marketing FOMO: teaser dinámico al canal Free cuando la oferta es Premium.
     * No incluye nombre ni enlace del producto; usa categoría, % de ahorro y botón a TELEGRAM_PREMIUM_JOIN_URL.
     */
    private function enviarTeaserOfertaBombaAlCanalFree(float $porcentajeAhorro, ?string $categoria = null): void
    {
        $token = config('services.telegram.token');
        $chatIdFree = config('services.telegram.chat_id_free');
        $linkPremium = config('services.telegram.premium_join_url', '');

        if (empty($token) || empty($chatIdFree)) {
            return;
        }

        $categoriaLabel = trim((string) $categoria) !== '' ? $this->escaparHtml($categoria) : 'Ofertas';
        $pct = number_format($porcentajeAhorro, 0);
        $texto = "🚨 <b>¡OFERTA BOMBA DETECTADA en {$categoriaLabel}!</b> 🚨\n\n"
            . "Un producto de esta categoría acaba de bajar un <b>{$pct}%</b>. 😱\n\n"
            . "🔒 Este enlace es exclusivo para miembros Premium. ¡Aprovecha antes de que se agote!";

        $payload = [
            'chat_id' => $chatIdFree,
            'text' => $texto,
            'parse_mode' => 'HTML',
        ];
        if (! empty($linkPremium)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => [
                    [['text' => '⭐ ¡Ver Oferta Premium ahora!', 'url' => $linkPremium]],
                ],
            ]);
        }

        Http::withOptions(['verify' => false])
            ->timeout(10)
            ->connectTimeout(5)
            ->asForm()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
    }

    /**
     * Envía la oferta solo como texto (sin foto). Usado cuando enviar_imagenes está desactivado o como fallback.
     * Si la oferta es Premium, envía también el teaser al canal Free.
     */
    public function enviarOfertaSoloTexto(Producto $producto): void
    {
        $porcentaje = $producto->porcentaje_ahorro !== null ? (float) $producto->porcentaje_ahorro : 0;
        $minimo = Configuracion::porcentajeMinimoNotificacion();
        $requiereAdicional = Configuracion::requiereDescuentoAdicional();
        if ($porcentaje < $minimo || ($requiereAdicional && ! $producto->permite_descuento_adicional)) {
            return;
        }
        $esPremium = $this->esOfertaPremium($porcentaje);
        if ($esPremium) {
            $this->enviarTeaserOfertaBombaAlCanalFree($porcentaje, $producto->categoria_origen ?? null);
        }
        $token = config('services.telegram.token');
        $chatId = $this->chatIdParaOferta($porcentaje);
        if (empty($token) || empty($chatId)) {
            return;
        }
        Log::info('NotificadorTelegram: oferta enviada a canal ' . ($esPremium ? 'Premium' : 'Free') . ' (solo texto)', [
            'producto_id' => $producto->id,
            'sku_tienda' => $producto->sku_tienda,
            'porcentaje_ahorro' => $porcentaje,
        ]);
        $caption = $this->construirCaption($producto, $porcentaje, $esPremium, true);
        $urlAfiliado = $producto->url_afiliado_completa ?? $producto->url_original;
        $this->enviarMensajeSinFoto($token, $chatId, $caption, $urlAfiliado);
    }

    /**
     * Construye el texto del mensaje según canal (Premium 💎 / Free 🔥) y si es solo texto (más emojis/separadores).
     */
    private function construirCaption(Producto $producto, float $porcentaje, bool $esPremium, bool $soloTexto): string
    {
        $precioOriginal = number_format((float) $producto->precio_original, 2);
        $precioOferta = $producto->precio_oferta !== null
            ? number_format((float) $producto->precio_oferta, 2)
            : $precioOriginal;
        $nombreLimpio = $this->escaparHtml(strip_tags((string) ($producto->nombre ?? '')));
        $tienda = $this->escaparHtml($producto->tienda_origen ?? '');
        $ahorro = number_format($porcentaje, 1);

        if ($esPremium) {
            $sep = $soloTexto ? "\n──────────────\n" : "\n";
            $lineas = [
                '💎 <b>OFERTA PREMIUM</b> 💎',
                $sep,
                '<b>' . $nombreLimpio . '</b>',
                '',
                '<b>Precio oferta: $' . $precioOferta . '</b>',
                'Precio original: <s>$' . $precioOriginal . '</s>',
                'Ahorro: <b>' . $ahorro . '%</b>',
                $soloTexto ? "\n──────────────" : '',
                $tienda,
            ];
        } else {
            $sep = $soloTexto ? "\n──────────────\n" : "\n";
            $lineas = [
                '🔥 <b>Nueva oferta</b> 🔥',
                $sep,
                '<b>' . $nombreLimpio . '</b>',
                '',
                '<b>Precio oferta: $' . $precioOferta . '</b>',
                'Precio original: <s>$' . $precioOriginal . '</s>',
                'Ahorro: <b>' . $ahorro . '%</b>',
                $soloTexto ? "\n──────────────" : '',
                $tienda,
            ];
        }

        return implode("\n", array_filter($lineas));
    }

    private function escaparHtml(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Envía solo texto (sin foto) con botón cuando el producto no tiene imagen_url.
     */
    private function enviarMensajeSinFoto(string $token, string $chatId, string $caption, ?string $urlAfiliado): void
    {
        $urlApi = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = [
            'chat_id' => $chatId,
            'text' => $caption,
            'parse_mode' => 'HTML',
        ];
        if (! empty($urlAfiliado)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => [
                    [['text' => 'Ver en Tienda', 'url' => $urlAfiliado]],
                ],
            ]);
        }

        $response = Http::withOptions(['verify' => false])
            ->timeout(10)
            ->connectTimeout(5)
            ->asForm()
            ->post($urlApi, $payload);

        if (! $response->successful()) {
            Log::error('NotificadorTelegram: fallo envío sendMessage (sin foto)', [
                'status' => $response->status(),
                'body' => $response->body(),
                'json' => $response->json(),
            ]);
        }
    }

    /**
     * Envía un mensaje de texto simple al chat configurado (p. ej. "Iniciando rastreo de Coppel...").
     */
    public function enviarMensajeSimple(string $texto): void
    {
        $token = config('services.telegram.token');
        $chatId = config('services.telegram.chat_id');

        if (empty($token) || empty($chatId)) {
            Log::debug('NotificadorTelegram: TELEGRAM_BOT_TOKEN o TELEGRAM_CHAT_ID no configurados.');

            return;
        }

        $urlApi = "https://api.telegram.org/bot{$token}/sendMessage";
        $payload = [
            'chat_id' => $chatId,
            'text' => $texto,
        ];

        $response = Http::withOptions(['verify' => false])
            ->timeout(10)
            ->connectTimeout(5)
            ->asForm()
            ->post($urlApi, $payload);

        if (! $response->successful()) {
            Log::error('NotificadorTelegram: fallo envío mensaje simple', [
                'status' => $response->status(),
                'body' => $response->body(),
                'json' => $response->json(),
            ]);
        }
    }
}
