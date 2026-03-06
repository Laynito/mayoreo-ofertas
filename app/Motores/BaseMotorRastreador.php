<?php

namespace App\Motores;

use App\Contratos\RastreadorTiendaInterface;
use App\Fabrica\RastreadorFabrica;
use App\Services\EstadoMotorService;
use App\Support\HttpRastreador;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Clase base para motores de rastreo con el cliente Http de Laravel (cabeceras de navegador, pausas).
 * Proxy global: si PROXY_URL está definida en .env, todo el tráfico pasa por ahí; si no, tráfico directo.
 */
abstract class BaseMotorRastreador implements RastreadorTiendaInterface
{
    protected int $peticionesRealizadas = 0;

    /**
     * URL base de la tienda (para Referer y construir enlaces).
     */
    abstract protected function getUrlBase(): string;

    /**
     * Ruta de ofertas o categoría a rastrear.
     */
    abstract protected function getRutaOfertas(): string;

    /**
     * Extrae productos del cuerpo de la respuesta (HTML/JSON). Implementación por tienda.
     * imagen_url es opcional (catálogo/panel). Las notificaciones a Telegram usan siempre captura
     * Browsershot de la página del producto (url_original), no la imagen del CDN.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    abstract protected function extraerProductosDeRespuesta(string $body, string $urlPagina): array;

    /**
     * User-Agent rotado (Chrome/Safari reales) para reducir bloqueos por tiendas que marcan scrapers.
     */
    protected function obtenerUserAgent(): string
    {
        $agents = config('rastreador.user_agents', []);
        if ($agents === []) {
            return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
        }

        return (string) $agents[array_rand($agents)];
    }

    /**
     * Cabeceras de sesión que simulan navegador real. User-Agent rotado para evitar baneos.
     */
    protected function obtenerCabecerasNavegador(string $refererBase): array
    {
        return [
            'User-Agent' => $this->obtenerUserAgent(),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'es-MX,es;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Referer' => rtrim($refererBase, '/') . '/',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'Sec-Ch-Ua' => '"Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Cache-Control' => 'max-age=0',
            'Connection' => 'keep-alive',
            'DNT' => '1',
        ];
    }

    /**
     * Control de flujo: pausa aleatoria de 2 a 5 segundos entre peticiones para no saturar la IP.
     */
    protected function pausarEntrePeticiones(): void
    {
        if ($this->peticionesRealizadas < 1) {
            return;
        }
        sleep(random_int(2, 5));
    }

    /**
     * Opciones adicionales por petición (cookies, etc.). Override en motor si aplica.
     *
     * @return array<string, mixed>
     */
    protected function getOpcionesPeticion(string $url): array
    {
        return [];
    }

    /**
     * Realiza una petición GET con reintentos y exponential backoff (429, 503, timeout).
     * Evita saturar servidores y reduce baneos permanentes.
     *
     * @return array{body: string, status: int}|null
     */
    protected function realizarPeticion(string $url): ?array
    {
        $this->pausarEntrePeticiones();

        $cabeceras = $this->obtenerCabecerasNavegador($this->getUrlBase());
        $opciones = $this->getOpcionesPeticion($url);
        if (isset($opciones['headers']) && is_array($opciones['headers'])) {
            $cabeceras = array_merge($cabeceras, $opciones['headers']);
        }

        $maxReintentos = config('rastreador.reintentos_maximos', 3);
        $baseBackoff = config('rastreador.backoff_base_segundos', 2);

        for ($intento = 0; $intento < $maxReintentos; $intento++) {
            $request = Http::withHeaders($cabeceras)->timeout(15)->connectTimeout(10);
            $request = HttpRastreador::conProxySiTexto($request, $url);

            try {
                $respuesta = $request->get($url);
                $this->peticionesRealizadas++;
                $status = $respuesta->status();

                $reintentar = in_array($status, [429, 503], true);
                if ($reintentar && $intento < $maxReintentos - 1) {
                    $espera = (int) pow($baseBackoff, $intento + 1);
                    Log::info(static::class . ': reintento con backoff', ['url' => $url, 'status' => $status, 'espera_s' => $espera]);
                    sleep($espera);
                    continue;
                }

                return [
                    'body' => $respuesta->body(),
                    'status' => $status,
                ];
            } catch (\Throwable $e) {
                $mensaje = $e->getMessage();
                $esTimeout = str_contains($mensaje, 'timeout') || str_contains($mensaje, 'timed out');
                $reintentar = $esTimeout && $intento < $maxReintentos - 1;

                if ($reintentar) {
                    $espera = (int) pow($baseBackoff, $intento + 1);
                    Log::info(static::class . ': reintento por timeout', ['url' => $url, 'espera_s' => $espera]);
                    sleep($espera);
                    continue;
                }

                Log::warning(static::class . ': error en petición', [
                    'url' => $url,
                    'mensaje' => $mensaje,
                ]);

                return null;
            }
        }

        return null;
    }

    /**
     * Recolecta datos: petición a ofertas y extracción. Respeta interfaz.
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    public function recolectarDatos(): array
    {
        $this->peticionesRealizadas = 0;
        $url = rtrim($this->getUrlBase(), '/') . '/' . ltrim($this->getRutaOfertas(), '/');

        $resultado = $this->realizarPeticion($url);

        if ($resultado === null) {
            Log::info(static::class . ': sin respuesta (timeout o excepción)', ['url' => $url]);

            return [];
        }

        if ($resultado['status'] !== 200) {
            Log::info(static::class . ': respuesta no 200 (ej. 403 Cloudflare)', [
                'url' => $url,
                'status' => $resultado['status'],
            ]);
            $this->registrarFalloSiBloqueo($resultado['status'], $resultado['body']);
        } elseif (str_contains($resultado['body'], 'Verifica tu identidad')) {
            $this->registrarFalloSiBloqueo(403, $resultado['body']);
        }

        // Siempre pasar body y URL al motor: si es 403 u otro, puede registrar la respuesta para diagnóstico.
        return $this->extraerProductosDeRespuesta($resultado['body'], $url);
    }

    /**
     * Registra fallo y marca motor como bloqueado si la respuesta indica 403 o captcha (para panel Filament).
     */
    protected function registrarFalloSiBloqueo(int $status, string $body): void
    {
        $nombreTienda = RastreadorFabrica::nombreTiendaDesdeClase(static::class);
        if ($nombreTienda === null) {
            return;
        }
        $mensaje = $status > 0 ? "HTTP {$status}" : 'Sin respuesta';
        if (str_contains($body, 'Verifica tu identidad')) {
            $mensaje .= '; Verifica tu identidad (bloqueo)';
        }
        app(EstadoMotorService::class)->registrarFallo($nombreTienda, $mensaje, $status);
    }
}
