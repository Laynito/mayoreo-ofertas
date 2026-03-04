<?php

namespace App\Contratos;

/**
 * Contrato para motores de rastreo de ofertas por tienda.
 * Cada motor (Walmart, Amazon, etc.) debe implementar esta interfaz.
 */
interface RastreadorTiendaInterface
{
    /**
     * Recolecta datos de productos desde la tienda (scraping o API).
     *
     * @return array<int, array{
     *     sku_tienda: string,
     *     nombre: string,
     *     precio_original: float,
     *     precio_oferta: float|null,
     *     imagen_url: string|null,
     *     url_original: string|null
     * }>
     */
    public function recolectarDatos(): array;
}
