<?php

namespace App\Services;

use App\Models\Producto;

class CalculadoraOfertas
{
    /**
     * Calcula el porcentaje de ahorro entre precio original y precio de oferta.
     * Si no hay oferta o el original es 0, devuelve null.
     *
     * @param  float  $precioOriginal  Precio original del producto.
     * @param  float|null  $precioOferta  Precio de oferta (null si no aplica).
     * @return float|null Porcentaje de ahorro (0-100) o null.
     */
    public function calcularPorcentajeAhorro(float $precioOriginal, ?float $precioOferta): ?float
    {
        if ($precioOferta === null || $precioOriginal <= 0) {
            return null;
        }

        if ($precioOferta >= $precioOriginal) {
            return 0.0;
        }

        $ahorro = (($precioOriginal - $precioOferta) / $precioOriginal) * 100;

        return round($ahorro, 2);
    }

    /**
     * Obtiene el precio final a mostrar según la restricción del producto.
     * Si permite_descuento_adicional es false, se ignora cualquier descuento
     * adicional y se devuelve el precio base (oferta o original).
     * Si es true, se puede aplicar un descuento adicional (por ejemplo desde
     * una regla de Filament).
     *
     * @param  Producto  $producto  Producto.
     * @param  float|null  $descuentoAdicionalPorcentaje  Porcentaje de descuento extra a aplicar (0-100). Solo se aplica si el producto lo permite.
     * @return float Precio final en pesos.
     */
    public function precioFinal(Producto $producto, ?float $descuentoAdicionalPorcentaje = null): float
    {
        $precioBase = $producto->precio_oferta ?? $producto->precio_original;
        $precioBase = (float) $precioBase;

        if (! $producto->permite_descuento_adicional) {
            return round($precioBase, 2);
        }

        if ($descuentoAdicionalPorcentaje === null || $descuentoAdicionalPorcentaje <= 0) {
            return round($precioBase, 2);
        }

        $descuentoAdicionalPorcentaje = min(100, $descuentoAdicionalPorcentaje);
        $precioConDescuento = $precioBase * (1 - $descuentoAdicionalPorcentaje / 100);

        return round($precioConDescuento, 2);
    }

    /**
     * Genera la URL de afiliado concatenando la URL original con el formato
     * de enlace profundo de Admitad (parámetros de seguimiento).
     *
     * @param  string  $urlOriginal  URL original del producto en la tienda.
     * @param  string  $idAdmitad  ID de afiliado Admitad (ej. subid o uid).
     * @param  array<string, string>  $params  Parámetros adicionales para el enlace (ej. ['subid' => 'campana1']).
     * @return string URL con parámetros de afiliado.
     */
    public function urlAfiliadoAdmitad(string $urlOriginal, string $idAdmitad, array $params = []): string
    {
        $params['subid'] = $idAdmitad;
        $separador = str_contains($urlOriginal, '?') ? '&' : '?';

        return $urlOriginal . $separador . http_build_query($params);
    }
}
