<?php

namespace App\Motores;

use App\Contratos\RastreadorTiendaInterface;
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
     *
     * @return array<int, array{sku_tienda: string, nombre: string, precio_original: float, precio_oferta: float|null, imagen_url: string|null, url_original: string|null}>
     */
    abstract protected function extraerProductosDeRespuesta(string $body, string $urlPagina): array;

    /**
     * Cabeceras de sesión que simulan Chrome en Windows 11 (visita humana).
     * User-Agent moderno y Accept-Language es-MX para reducir bloqueos.
     */
    protected function obtenerCabecerasNavegador(string $refererBase): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
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
     * Realiza una petición GET con el cliente Http de Laravel (pausa previa si corresponde).
     * Proxy solo para texto (HTML/API); las URLs de imagen se piden con IP local. Manejo de excepciones y Log::warning.
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

        $request = Http::withHeaders($cabeceras)->timeout(15)->connectTimeout(10);
        $request = HttpRastreador::conProxySiTexto($request, $url);

        try {
            $respuesta = $request->get($url);
            $this->peticionesRealizadas++;

            return [
                'body' => $respuesta->body(),
                'status' => $respuesta->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning(static::class . ': error en petición', [
                'url' => $url,
                'mensaje' => $e->getMessage(),
            ]);

            return null;
        }
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
        }

        // Siempre pasar body y URL al motor: si es 403 u otro, puede registrar la respuesta para diagnóstico.
        return $this->extraerProductosDeRespuesta($resultado['body'], $url);
    }
}
