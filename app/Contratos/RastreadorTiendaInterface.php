<?php

namespace App\Contratos;

/**
 * Contrato para motores de rastreo de ofertas por tienda.
 * Cada motor (Calimax, Coppel, Elektra, etc.) implementa esta interfaz.
 * Las notificaciones a Telegram usan siempre captura Browsershot de url_original; imagen_url no se usa para enviar.
 */
interface RastreadorTiendaInterface
{
    /**
     * Recolecta datos de productos desde la tienda (scraping o API).
     * imagen_url es opcional; Telegram recibe captura de la página del producto (Browsershot).
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
