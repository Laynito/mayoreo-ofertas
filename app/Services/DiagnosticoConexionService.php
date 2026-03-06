<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Support\HttpRastreador;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Servicio para las pruebas del panel "Diagnóstico de Conexión" (Centro de Control).
 */
final class DiagnosticoConexionService
{
    /** URL de ML para obtener el usuario autenticado (confirma que el Access Token es válido). */
    private const ML_USERS_ME = 'https://api.mercadolibre.com/users/me';

    /** Servicio que devuelve la IP del cliente (para comprobar proxy). */
    private const URL_IP_HTTPS = 'https://api.ipify.org?format=json';
    private const URL_IP_HTTP = 'http://api.ipify.org?format=json';

    /**
     * Prueba el Access Token de Mercado Libre: GET /users/me y devuelve el nickname de la App/usuario.
     * Si falla, devuelve modo_scraping=true para que el Centro de Control muestre el aviso naranja.
     *
     * @return array{ok: bool, mensaje: string, detalle?: array, modo_scraping?: bool}
     */
    public function probarApiMercadoLibre(): array
    {
        $token = MercadoLibreTokenService::obtenerAccessTokenValido();
        if ($token === null || $token === '') {
            return ['ok' => false, 'mensaje' => 'No hay Access Token válido. Conecta desde /mercado-libre/login.', 'modo_scraping' => true];
        }

        $proxyUrl = Configuracion::getProxyUrl();
        $url = $proxyUrl !== null
            ? HttpRastreador::urlApiMlParaProxy(self::ML_USERS_ME)
            : self::ML_USERS_ME;

        $request = Http::withHeaders(HttpRastreador::headersNavegador())
            ->withToken($token)
            ->timeout(20)
            ->connectTimeout(15);
        $request = HttpRastreador::conProxy($request);
        try {
            $response = $request->get($url);
        } catch (ConnectionException $e) {
            $mensaje = 'Error de conexión: ' . $e->getMessage();
            if ($proxyUrl === null && (str_contains($e->getMessage(), '35') || str_contains($e->getMessage(), 'SSL'))) {
                $mensaje .= ' En este servidor la conexión directa a Mercado Libre suele fallar. Activa el proxy en Ajustes (Proxy y Rastreo) para que las peticiones pasen por el proxy.';
            }
            return ['ok' => false, 'mensaje' => $mensaje, 'modo_scraping' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensaje' => 'Error: ' . $e->getMessage(), 'modo_scraping' => true];
        }

        if (! $response->successful()) {
            $body = $response->json();
            $mensaje = $body['message'] ?? $response->body();

            return ['ok' => false, 'mensaje' => 'Error ' . $response->status() . ': ' . $mensaje, 'modo_scraping' => true];
        }

        $data = $response->json();
        $nickname = $data['nickname'] ?? $data['id'] ?? '—';
        $id = $data['id'] ?? '—';

        return [
            'ok' => true,
            'mensaje' => "Token válido. Usuario: {$nickname} (ID: {$id}).",
            'detalle' => $data,
        ];
    }

    /**
     * Prueba el proxy: hace una petición a un servicio que devuelve la IP vista desde el proxy.
     * Si HTTPS falla por SSL (cURL 35), intenta por HTTP para poder mostrar la IP igualmente.
     *
     * @return array{ok: bool, mensaje: string, ip?: string}
     */
    public function probarProxy(): array
    {
        $proxyUrl = Configuracion::getProxyUrl();
        if ($proxyUrl === null || $proxyUrl === '') {
            return ['ok' => false, 'mensaje' => 'Proxy no configurado o desactivado. Actívalo en Proxy y Rastreo.'];
        }

        $opciones = HttpRastreador::opcionesProxy();
        $client = Http::withHeaders(HttpRastreador::headersNavegador())
            ->timeout(15)
            ->connectTimeout(10)
            ->withOptions($opciones);

        $resultado = $this->obtenerIpDesdeUrl($client, self::URL_IP_HTTPS);
        if ($resultado !== null) {
            return $resultado;
        }

        $resultado = $this->obtenerIpDesdeUrl($client, self::URL_IP_HTTP);
        if ($resultado !== null) {
            if ($resultado['ok']) {
                $resultado['mensaje'] = "Proxy activo. IP detectada: {$resultado['ip']} (vía HTTP; HTTPS falló por SSL en este servidor).";
            }
            return $resultado;
        }

        return ['ok' => false, 'mensaje' => 'No se pudo obtener la IP ni por HTTPS ni por HTTP. Revisa la URL del proxy o la conectividad del servidor.'];
    }

    /**
     * Intenta obtener la IP desde una URL. Devuelve array de resultado si hay IP, o null si hubo excepción (para intentar otra URL).
     *
     * @return array{ok: bool, mensaje: string, ip?: string}|null
     */
    private function obtenerIpDesdeUrl(PendingRequest $client, string $url): ?array
    {
        try {
            $response = $client->get($url);
        } catch (ConnectionException $e) {
            return null;
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensaje' => 'Error: ' . $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'mensaje' => 'Error ' . $response->status() . ' — ' . $response->body()];
        }

        $data = $response->json();
        $ip = is_array($data) ? ($data['ip'] ?? null) : null;
        if ($ip === null || $ip === '') {
            return ['ok' => false, 'mensaje' => 'La respuesta no incluyó la IP.'];
        }

        return ['ok' => true, 'mensaje' => "Proxy activo. IP detectada: {$ip}", 'ip' => $ip];
    }

    /**
     * Simula la monetización de un enlace: devuelve cómo quedaría con tag Amazon, micosmtics ML o Admitad.
     *
     * @return array{ok: bool, url: string, tipo: string, mensaje?: string}
     */
    public function simularEnlace(string $urlEntrada): array
    {
        $url = trim($urlEntrada);
        if ($url === '' || ! str_starts_with($url, 'http')) {
            return ['ok' => false, 'url' => '', 'tipo' => '', 'mensaje' => 'Escribe una URL que empiece por http:// o https://'];
        }

        $host = parse_url($url, PHP_URL_HOST);
        $host = $host !== null ? strtolower($host) : '';

        if (str_contains($host, 'amazon.') || str_contains($host, 'amazon.com.mx')) {
            $normalizador = app(NormalizadorEnlacesAfiliadoService::class);
            $resultado = $normalizador->normalizarUrlAmazon($url);

            return [
                'ok' => true,
                'url' => $resultado ?? $url,
                'tipo' => 'Amazon (tag ' . (Configuracion::getAmazonTag() ?: 'micosmtics-20') . ')',
            ];
        }

        if (str_contains($host, 'mercadolibre.') || str_contains($host, 'mercadolibre.com')) {
            $normalizador = app(NormalizadorEnlacesAfiliadoService::class);
            $resultado = $normalizador->normalizarUrlMercadoLibre($url);

            return [
                'ok' => true,
                'url' => $resultado ?? $url,
                'tipo' => 'Mercado Libre (micosmtics=' . (Configuracion::getMlAffiliateId() ?: '187001804') . ')',
            ];
        }

        if (str_contains($host, 'coppel.com') || str_contains($url, 'coppel.com')) {
            $affiliate = app(AffiliateLinkService::class);
            $resultado = $affiliate->enlaceParaTelegram($url, 'Coppel');

            return [
                'ok' => true,
                'url' => $resultado,
                'tipo' => 'Admitad (Coppel)',
            ];
        }

        return [
            'ok' => true,
            'url' => $url,
            'tipo' => 'Sin monetización (dominio no reconocido: Amazon, ML o Coppel).',
        ];
    }
}
