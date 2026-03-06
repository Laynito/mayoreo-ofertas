<?php

namespace App\Services;

/**
 * Genera enlaces de afiliado Admitad en formato Deeplink.
 * Para tiendas como Walmart, Sam's Club y Costco que no usan un parámetro simple.
 *
 * Formato: https://ad.admitad.com/g/CODIGO_SITIO/?ulp=URL_PRODUCTO_CODIFICADA
 */
final class AdmitadService
{
    private const URL_BASE_DEEPLINK = 'https://ad.admitad.com/g/';

    /**
     * Envuelve la URL del producto en el deeplink de Admitad.
     * Si no hay código de sitio configurado, devuelve la URL original.
     */
    public function generarDeeplink(string $urlProducto): string
    {
        if ($urlProducto === '') {
            return '';
        }

        $codigoSitio = config('services.admitad.codigo_sitio');
        if ($codigoSitio === null || $codigoSitio === '') {
            return $urlProducto;
        }

        $ulp = rawurlencode($urlProducto);

        return rtrim(self::URL_BASE_DEEPLINK, '/') . '/' . trim($codigoSitio) . '/?ulp=' . $ulp;
    }
}
