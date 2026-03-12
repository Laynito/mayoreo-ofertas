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

    private int $lastRetryAfter = 0;

    public function __construct()
    {
        $token = config('services.telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$token}";
        $this->chatId = config('services.telegram.chat_id_free');
    }

    public function getLastRetryAfter(): int
    {
        return $this->lastRetryAfter;
    }

    /**
     * Envía una oferta al canal. Enlace: URL de tracking (/out/{id}) para registrar clicks (ML, Coppel, etc.).
     */
    public function sendOffer(Producto $producto): bool
    {
        $urlAfiliado = url()->route('out', ['producto' => $producto->id]);
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
            $precioAyer !== null ? number_format((float) $precioAyer, 2) : null,
            $producto->tienda ?? null
        );

        // 1. Verificar si la imagen está guardada localmente en disco (prioridad)
        $localPath = $this->getLocalImagePath($producto->url_imagen ?? '');

        try {
            if ($localPath && file_exists($localPath)) {
                // Imagen local → enviar desde disco directamente
                $response = $this->sendPhotoFromDisk($localPath, $caption);

                // Si falla (ej. AVIF no convertido), intentar como texto
                if (! $response->successful()) {
                    Log::warning('TelegramService: fallo desde disco, enviando sin imagen', [
                        'producto_id' => $producto->id,
                        'path'        => $localPath,
                        'response'    => $response->body(),
                    ]);
                    $response = Http::post("{$this->baseUrl}/sendMessage", [
                        'chat_id'                  => $this->chatId,
                        'text'                     => $caption,
                        'parse_mode'               => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                }
            } else {
                // 2. URL externa — solo intentar si es válida (no localhost)
                $photoUrl = $this->isValidPhotoUrl($producto->url_imagen)
                    ? $this->cleanPhotoUrl($producto->url_imagen)
                    : null;

                if ($photoUrl) {
                    $response = Http::post("{$this->baseUrl}/sendPhoto", [
                        'chat_id'                  => $this->chatId,
                        'photo'                    => $photoUrl,
                        'caption'                  => $caption,
                        'parse_mode'               => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);

                    if (! $response->successful() && $this->isInvalidPhotoError($response->body())) {
                        $response = $this->sendPhotoAsFile($photoUrl, $caption) ?? $response;
                    }
                } else {
                    $response = null;
                }

                // Si no hay imagen o falla, enviar solo texto
                if (! $response || ! $response->successful()) {
                    $response = Http::post("{$this->baseUrl}/sendMessage", [
                        'chat_id'                  => $this->chatId,
                        'text'                     => $caption,
                        'parse_mode'               => 'HTML',
                        'disable_web_page_preview' => true,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Error Telegram: ' . $e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            $body = $response->body();

            // Capturar retry_after si Telegram indica rate limit (429)
            $data = json_decode($body, true);
            $this->lastRetryAfter = (int) ($data['parameters']['retry_after'] ?? 0);

            Log::warning('TelegramService: fallo envío', [
                'producto_id' => $producto->id,
                'response'    => $body,
            ]);

            return false;
        }

        $this->lastRetryAfter = 0;

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
        ?string $precioAyer = null,
        ?string $tienda = null
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

        $nombre = mb_substr($nombre, 0, 150);
        $text = $tituloEmotivo . "\n\n";
        $text .= "📦 <b>" . $this->escapeHtml($nombre) . "</b>\n";
        if ($tienda) {
            $text .= "🏪 " . $this->escapeHtml($tienda) . "\n";
        }
        $text .= "💰 Antes: <s>\${$precioOriginal}</s>\n";
        // Solo mostrar "Ayer" si es distinto de "Ahora" para no duplicar el precio (ej. después de medianoche)
        if ($precioAyer !== null && $precioAyer !== $precioActual) {
            $text .= "📅 Ayer: \${$precioAyer}\n";
        }
        $text .= "✅ <b>Ahora: \${$precioActual}</b>\n\n";
        $linkText = $tienda ? 'Ver en ' . $tienda : 'Ver Oferta';
        $text .= "🔗 <a href=\"" . $this->escapeHtml($urlAfiliado) . "\">" . $this->escapeHtml($linkText) . "</a>";

        return mb_substr($text, 0, 1024);
    }

    private function escapeHtml(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    /**
     * Convierte una URL pública local (APP_URL/storage/...) a ruta absoluta en disco.
     * Retorna null si no es una URL local.
     */
    private function getLocalImagePath(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        // Buscar el segmento /storage/ en la URL sin importar el host/APP_URL
        // Funciona con http://localhost/storage/... o https://dominio.com/storage/...
        $marker = '/storage/imagenes/';
        $pos = strpos($url, $marker);
        if ($pos === false) {
            return null;
        }

        $relative = substr($url, $pos + strlen('/storage/'));
        $path = storage_path('app/public/' . $relative);

        return $path;
    }

    /**
     * Envía una imagen desde el disco local a Telegram como multipart.
     * Si el formato es AVIF (no soportado por Telegram), lo convierte a JPEG primero.
     */
    private function sendPhotoFromDisk(string $localPath, string $caption): \Illuminate\Http\Client\Response
    {
        $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));

        // Telegram no soporta AVIF ni WebP con transparencia — convertir a JPEG
        if (in_array($ext, ['avif', 'webp'])) {
            $jpgPath = preg_replace('/\.(avif|webp)$/i', '.jpg', $localPath);

            // Intentar convertir con ImageMagick si no existe el JPG
            if (! file_exists($jpgPath)) {
                exec("convert " . escapeshellarg($localPath) . " -quality 85 " . escapeshellarg($jpgPath) . " 2>/dev/null", $out, $code);
            }

            if (file_exists($jpgPath)) {
                // Usar el JPG convertido y eliminar el AVIF original
                @unlink($localPath);
                $localPath = $jpgPath;
                $ext = 'jpg';
            }
        }

        $imageData = file_get_contents($localPath);
        $mimeMap   = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
        $mime      = $mimeMap[$ext] ?? 'image/jpeg';

        return Http::attach('photo', $imageData, "photo.{$ext}", ['Content-Type' => $mime])
            ->post("{$this->baseUrl}/sendPhoto", [
                'chat_id'                  => $this->chatId,
                'caption'                  => $caption,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]);
    }

    /**
     * Descarga la imagen desde el servidor y la sube a Telegram como archivo multipart.
     * Útil cuando el CDN del proveedor bloquea peticiones directas de Telegram.
     * Retorna null si no se puede descargar la imagen.
     */
    private function sendPhotoAsFile(string $photoUrl, string $caption): ?\Illuminate\Http\Client\Response
    {
        try {
            // Descargar imagen con headers de navegador para evitar bloqueos de CDN
            $imgResponse = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36',
                'Accept'     => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Referer'    => parse_url($photoUrl, PHP_URL_SCHEME) . '://' . parse_url($photoUrl, PHP_URL_HOST) . '/',
            ])->timeout(15)->get($photoUrl);

            if (! $imgResponse->successful()) {
                return null;
            }

            $contentType = $imgResponse->header('Content-Type') ?? 'image/jpeg';
            // Solo procesar si es una imagen real
            if (! str_starts_with($contentType, 'image/')) {
                return null;
            }

            $ext = match (true) {
                str_contains($contentType, 'png')  => 'png',
                str_contains($contentType, 'webp') => 'webp',
                str_contains($contentType, 'gif')  => 'gif',
                default                            => 'jpg',
            };

            $imgData = $imgResponse->body();
            if (strlen($imgData) < 1000) {
                return null; // imagen demasiado pequeña, probablemente error
            }

            return Http::attach('photo', $imgData, "photo.{$ext}", ['Content-Type' => $contentType])
                ->post("{$this->baseUrl}/sendPhoto", [
                    'chat_id'                  => $this->chatId,
                    'caption'                  => $caption,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
        } catch (\Throwable $e) {
            Log::warning('TelegramService: no se pudo subir imagen como archivo', [
                'url'   => $photoUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Limpia la URL de imagen removiendo parámetros que confunden a Telegram
     * (ej. ?iresize=width:255 de Coppel que devuelven contenido no-imagen).
     */
    private function cleanPhotoUrl(string $url): string
    {
        // Coppel: cdn5.coppel.com/pm/SKU-1.jpg?iresize=width:255 → quitar query params
        $parsed = parse_url($url);
        $host = strtolower($parsed['host'] ?? '');
        if (str_contains($host, 'coppel.com')) {
            return strtok($url, '?');
        }

        return $url;
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
            || str_contains($body, 'wrong file identifier')
            || str_contains($body, 'wrong type of the web page content')
            || str_contains($body, 'failed to get HTTP URL content')
            || str_contains($body, 'PHOTO_INVALID_DIMENSIONS')
            || str_contains($body, 'URL host is empty');
    }
}
