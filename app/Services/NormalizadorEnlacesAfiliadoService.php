<?php

namespace App\Services;

use App\Models\Configuracion;

/**
 * Normaliza URLs por red de afiliados (Mercado Libre, Amazon).
 *
 * Mercado Libre (prioridad manual / modo scraping):
 * - Sin API certificada o si MercadoLibreShortUrlService falla, se inyecta siempre &micosmtics=187001804 de forma manual.
 * - Limpia parámetros de rastreo ajenos (utm_*, click_id, mclics). NUNCA se elimina nuestro ID.
 * - Fuerza siempre &micosmtics=187001804 (o ID configurado en Ajustes) al final; si ya existía otro micosmtics se reemplaza por el nuestro.
 * - Usa ? si la URL no tiene query; & si ya tiene parámetros.
 *
 * Amazon:
 * - Inyecta tag=micosmtics-20 (o el de .env) al final de la URL.
 */
final class NormalizadorEnlacesAfiliadoService
{
    /** ID de afiliado ML por defecto (modo manual/scraping sin API certificada). No depende de token ni API. */
    private const ID_AFILIADO_DEFECTO = '187001804';

    /**
     * Parámetros de rastreo que se eliminan (no son de nuestro afiliado).
     *
     * @var list<string>
     */
    private const PARAMETROS_RESTRINGIDOS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
        'click_id',
        'mclics',
        'micosmtics', // Se reemplaza por el nuestro al final
    ];

    /**
     * Devuelve la URL limpia para auditoría (sin parámetros de rastreo ni afiliado).
     */
    public function urlLimpiaParaAuditoria(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parsed = parse_url($url);
        if (! is_array($parsed) || empty($parsed['host'])) {
            return $this->quitarParametrosRastreoDesdeQueryString($url);
        }

        $base = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'] . ($parsed['path'] ?? '/');
        $query = $parsed['query'] ?? '';
        if ($query === '') {
            return rtrim($base, '/');
        }

        $params = $this->filtrarParamsRastreo($query);
        if ($params === []) {
            return rtrim($base, '/');
        }

        return rtrim($base, '/') . '?' . http_build_query($params);
    }

    /**
     * Deja la URL de ML solo hasta el path del producto; quita todo lo que venga después de ? o #.
     * Ejemplo: https://articulo.mercadolibre.com.mx/MLM-123456789-nombre?utm_source=1 → https://articulo.mercadolibre.com.mx/MLM-123456789-nombre
     */
    private function urlMercadoLibreSoloPath(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        return (string) preg_replace('/[\?#].*$/', '', $url);
    }

    /**
     * Si la URL es de producto catálogo ML (contiene /p/MLM...), devuelve la forma corta canónica
     * para evitar 404 o redirección a login por slug desactualizado.
     * Ejemplo: .../hidrolavadora-electrica-.../p/MLM53177027?... → https://www.mercadolibre.com.mx/p/MLM53177027?micosmtics=...
     */
    public function urlMercadoLibreCanonicaCorta(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || ! str_contains($url, 'mercadolibre')) {
            return null;
        }
        if (preg_match('/\/p\/(MLM\d+)/i', $url, $m)) {
            $idCatalogo = $m[1];
            $idAfiliado = Configuracion::getMlAffiliateId() ?: self::ID_AFILIADO_DEFECTO;
            $idAfiliado = $idAfiliado !== '' ? $idAfiliado : self::ID_AFILIADO_DEFECTO;
            $host = str_contains($url, 'mercadolibre.com.mx') ? 'www.mercadolibre.com.mx' : 'www.mercadolibre.com.ar';
            if (preg_match('/mercadolibre\.(com\.[a-z]{2})/', $url, $dom)) {
                $host = 'www.mercadolibre.' . $dom[1];
            }
            return 'https://' . $host . '/p/' . $idCatalogo . '?micosmtics=' . $idAfiliado;
        }
        return null;
    }

    /**
     * Normaliza la URL para Mercado Libre: usa forma corta canónica si es /p/MLM...; si no, deja solo scheme+host+path
     * y añade micosmtics. Evita slugs largos que llevan a "página no encontrada" o login.
     */
    public function normalizarUrlMercadoLibre(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $corta = $this->urlMercadoLibreCanonicaCorta($url);
        if ($corta !== null) {
            return $corta;
        }

        $limpia = $this->urlMercadoLibreSoloPath($url);
        $limpia = rtrim($limpia, '/');
        if ($limpia === '' || ! str_contains($limpia, 'mercadolibre')) {
            return null;
        }

        $idAfiliado = Configuracion::getMlAffiliateId() ?: self::ID_AFILIADO_DEFECTO;
        $idAfiliado = $idAfiliado !== '' ? $idAfiliado : self::ID_AFILIADO_DEFECTO;
        $suffix = 'micosmtics=' . $idAfiliado;

        return str_contains($limpia, '?')
            ? $limpia . '&' . $suffix
            : $limpia . '?' . $suffix;
    }

    /**
     * Normaliza la URL para Amazon México: inyecta tag de afiliado al final (config services.amazon_tag).
     * Si la URL ya tiene tag=, lo reemplaza por el nuestro. El ID de afiliado nunca se pierde.
     */
    public function normalizarUrlAmazon(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $tag = Configuracion::getAmazonTag();
        if ($tag === null || $tag === '') {
            $tag = 'micosmtics-20';
        }
        $tag = (string) $tag;

        // Quitar cualquier tag previo y forzar el nuestro al final
        if (str_contains($url, 'tag=')) {
            $url = (string) preg_replace('/[?&]tag=[^&]*/', '', $url);
            $url = rtrim($url, '?&');
        }

        $separador = str_contains($url, '?') ? '&' : '?';

        return $url . $separador . 'tag=' . $tag;
    }

    /**
     * Filtra los parámetros de la query string eliminando los de rastreo.
     *
     * @return array<string, string>
     */
    private function filtrarParamsRastreo(string $queryString): array
    {
        parse_str($queryString, $params);
        if (! is_array($params)) {
            return [];
        }

        foreach (array_keys($params) as $key) {
            $keyLower = strtolower((string) $key);
            if (str_starts_with($keyLower, 'utm_')) {
                unset($params[$key]);
                continue;
            }
            foreach (self::PARAMETROS_RESTRINGIDOS as $restringido) {
                if ($keyLower === $restringido) {
                    unset($params[$key]);
                    break;
                }
            }
        }

        return $params;
    }

    /**
     * Cuando parse_url no devuelve host (URL relativa o malformada), limpia a mano.
     */
    private function quitarParametrosRastreoDesdeQueryString(string $url): string
    {
        $pos = strpos($url, '?');
        if ($pos === false) {
            return $url;
        }

        $base = substr($url, 0, $pos);
        $query = substr($url, $pos + 1);
        $params = $this->filtrarParamsRastreo($query);

        return $params === [] ? $base : $base . '?' . http_build_query($params);
    }
}
