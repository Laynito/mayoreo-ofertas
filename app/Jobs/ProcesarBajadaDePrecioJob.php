<?php

namespace App\Jobs;

use App\Models\Configuracion;
use App\Models\HistorialPrecio;
use App\Models\Producto;
use App\Services\NotificadorTelegram;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Evalúa si el precio actual del producto es al menos un 20% más barato que el
 * último registro en el historial (precio "ayer") y, si aplica, notifica por Telegram
 * con captura de pantalla (Browsershot) mostrando "Precio Ayer" vs "Precio Hoy".
 *
 * Respeta la restricción por producto: si permite_descuento_adicional es false,
 * no se notifica la bajada.
 */
class ProcesarBajadaDePrecioJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Porcentaje mínimo de bajada para considerar "bajada histórica" (20%). */
    public const UMBRAL_BAJADA_PORCENTAJE = 20.0;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * @param  array<int, int>|null  $productoIds  Si se pasa, solo se evalúan estos productos; si null, todos con historial.
     */
    public function __construct(
        protected ?array $productoIds = null
    ) {}

    public function handle(NotificadorTelegram $notificador): void
    {
        $productos = $this->obtenerProductosAEvaluar();

        foreach ($productos as $producto) {
            $this->evaluarYNotificarSiBajada($producto, $notificador);
        }
    }

    /**
     * Productos a evaluar: los indicados en el Job o todos con al menos dos registros en historial.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Producto>
     */
    protected function obtenerProductosAEvaluar(): \Illuminate\Database\Eloquent\Collection
    {
        $query = Producto::query()
            ->whereHas('historialPrecios', fn ($q) => $q->whereRaw('1 = 1'), '>=', 2);

        if ($this->productoIds !== null && $this->productoIds !== []) {
            $query->whereIn('id', $this->productoIds);
        }

        return $query->get();
    }

    /**
     * Obtiene el precio de referencia "ayer" (penúltimo registro del historial).
     */
    protected function obtenerPrecioAyer(Producto $producto): ?float
    {
        $registros = HistorialPrecio::query()
            ->where('producto_id', $producto->id)
            ->orderByDesc('registrado_en')
            ->limit(2)
            ->get();

        if ($registros->count() < 2) {
            return null;
        }

        $ayer = $registros->last();
        $precio = $ayer->precio_oferta ?? $ayer->precio_original;

        return $precio !== null ? (float) $precio : null;
    }

    /**
     * Precio actual del producto (hoy).
     */
    protected function obtenerPrecioHoy(Producto $producto): float
    {
        $precio = $producto->precio_oferta ?? $producto->precio_original;

        return (float) ($precio ?? 0);
    }

    /**
     * Calcula el porcentaje de bajada: (precio_ayer - precio_hoy) / precio_ayer * 100.
     */
    protected function calcularPorcentajeBajada(float $precioAyer, float $precioHoy): ?float
    {
        if ($precioAyer <= 0) {
            return null;
        }
        if ($precioHoy >= $precioAyer) {
            return null;
        }

        return ((float) $precioAyer - (float) $precioHoy) / (float) $precioAyer * 100;
    }

    protected function evaluarYNotificarSiBajada(Producto $producto, NotificadorTelegram $notificador): void
    {
        if (Configuracion::requiereDescuentoAdicional() && ! $producto->permite_descuento_adicional) {
            return;
        }

        $precioAyer = $this->obtenerPrecioAyer($producto);
        if ($precioAyer === null || $precioAyer <= 0) {
            return;
        }

        $precioHoy = $this->obtenerPrecioHoy($producto);
        $porcentajeBajada = $this->calcularPorcentajeBajada($precioAyer, $precioHoy);

        if ($porcentajeBajada === null || $porcentajeBajada < self::UMBRAL_BAJADA_PORCENTAJE) {
            return;
        }

        try {
            $notificador->notificarBajadaHistoricaConCaptura($producto, $precioAyer, $precioHoy);
        } catch (\Throwable $e) {
            Log::warning('ProcesarBajadaDePrecioJob: fallo al notificar bajada histórica', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
