<?php

namespace App\Console\Commands;

use App\Models\Configuracion;
use App\Support\HttpRastreador;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envía una foto de prueba al canal de Telegram por multipart (descarga + attach).
 * --public: usa una imagen pública (picsum) para comprobar que el envío multipart funciona.
 */
class TestTelegramFoto extends Command
{
    protected $signature = 'test:telegram-foto
                            {--public : Usar imagen pública (picsum) en lugar de ML para probar multipart}';

    protected $description = 'Envía una foto de prueba al canal Telegram (.env) por multipart';

    private const URL_FOTO_ML = 'https://http2.mlstatic.com/D_NQ_NP_614510-MLM54111353453_032023-O.jpg';

    /** Imagen pública para probar que multipart funciona (no depende de ML/CDN). */
    private const URL_FOTO_PUBLICA = 'https://picsum.photos/400/300';

    public function handle(): int
    {
        $token = Configuracion::getTelegramToken();
        $chatId = Configuracion::getTelegramChatId();

        if (empty($token)) {
            Log::warning('test:telegram-foto: TELEGRAM_BOT_TOKEN no configurado');
            $this->error('TELEGRAM_BOT_TOKEN no configurado. Configura en Ajustes o .env y ejecuta: php artisan config:clear');
            return self::FAILURE;
        }

        if (empty($chatId)) {
            Log::warning('test:telegram-foto: TELEGRAM_CHAT_ID no configurado');
            $this->error('TELEGRAM_CHAT_ID no configurado. Configura en Ajustes o .env.');
            return self::FAILURE;
        }

        $usarPublica = (bool) $this->option('public');
        $url = $usarPublica ? self::URL_FOTO_PUBLICA : self::URL_FOTO_ML;
        $this->info('Descargando imagen de prueba...');
        $this->line('URL: ' . $url);

        $contenido = $this->descargarImagen($url, $usarPublica);
        if ($contenido === null || $contenido === '') {
            Log::warning('test:telegram-foto: no se pudo descargar la imagen', ['url' => $url]);
            $this->error('No se pudo descargar la imagen.');
            if (! $usarPublica) {
                $this->line('Prueba con: php artisan test:telegram-foto --public (imagen pública) para ver si el envío multipart funciona.');
            }
            return self::FAILURE;
        }

        if (! $this->esImagenValida($contenido)) {
            Log::warning('test:telegram-foto: la descarga no es imagen válida (magic bytes)', ['url' => $url]);
            $this->error('La descarga no devolvió una imagen válida (JPEG/PNG). Posible página de error o bloqueo.');
            if (! $usarPublica) {
                $this->line('Prueba con: php artisan test:telegram-foto --public');
            }
            return self::FAILURE;
        }

        [$nombreArchivo, $contentType] = $this->tipoImagenParaAdjunto($contenido);
        $this->info('Enviando foto por multipart (sendPhoto + attach)...');
        $urlApi = "https://api.telegram.org/bot{$token}/sendPhoto";
        $payload = [
            'chat_id' => $chatId,
            'caption' => $usarPublica
                ? '🖼️ Foto de prueba (test:telegram-foto --public)'
                : '🖼️ Foto de prueba (test:telegram-foto) — imagen Mercado Libre',
            'parse_mode' => 'HTML',
        ];

        $response = Http::withOptions(['verify' => false])
            ->timeout(25)
            ->connectTimeout(8)
            ->attach('photo', $contenido, $nombreArchivo, ['Content-Type' => $contentType])
            ->post($urlApi, $payload);

        if ($response->successful()) {
            Log::info('test:telegram-foto: foto enviada correctamente por multipart');
            $this->info('Foto enviada por multipart. Revisa tu canal de Telegram.');
            return self::SUCCESS;
        }

        Log::error('test:telegram-foto: API Telegram falló', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        $this->error('La API de Telegram devolvió un error.');
        $this->line('Status HTTP: ' . $response->status());
        $this->line('Respuesta: ' . $response->body());
        return self::FAILURE;
    }

    private function descargarImagen(string $url, bool $publica): ?string
    {
        $opciones = ['verify' => false, 'timeout' => 15, 'connectTimeout' => 5];
        $request = Http::withOptions($opciones);

        if (! $publica) {
            $request = $request->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'Referer' => 'https://www.mercadolibre.com.mx/',
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            ]);
            // Misma estrategia que NotificadorTelegram: proxy para ML evita bloqueo en Hostinger.
            $request = HttpRastreador::conProxy($request);
        }

        $response = $request->get($url);
        if (! $response->successful()) {
            Log::debug('test:telegram-foto: descarga fallida', ['url' => $url, 'status' => $response->status()]);
            return null;
        }
        $body = $response->body();
        if ($body === '' || strlen($body) < 100) {
            return null;
        }
        return $body;
    }

    private function esImagenValida(string $contenido): bool
    {
        if (strlen($contenido) < 4) {
            return false;
        }
        return str_starts_with($contenido, "\xFF\xD8\xFF")  // JPEG
            || str_starts_with($contenido, "\x89PNG")       // PNG
            || str_starts_with($contenido, "GIF");         // GIF
    }

    /**
     * Devuelve [nombreArchivo, Content-Type] para el adjunto. Telegram necesita el MIME correcto para mostrar la imagen.
     *
     * @return array{0: string, 1: string}
     */
    private function tipoImagenParaAdjunto(string $contenido): array
    {
        if (strlen($contenido) >= 3 && str_starts_with($contenido, "\xFF\xD8\xFF")) {
            return ['foto-prueba.jpg', 'image/jpeg'];
        }
        if (strlen($contenido) >= 4 && str_starts_with($contenido, "\x89PNG")) {
            return ['foto-prueba.png', 'image/png'];
        }
        if (strlen($contenido) >= 3 && str_starts_with($contenido, "GIF")) {
            return ['foto-prueba.gif', 'image/gif'];
        }
        return ['foto-prueba.jpg', 'image/jpeg'];
    }
}
