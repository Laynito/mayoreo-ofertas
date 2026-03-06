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
 * Evalúa si el precio actual del producto tiene una bajada significativa respecto al
 * último registro en el historial (precio "ayer") y, si aplica, notifica por Telegram
 * vía enviarOfertaSegunCalidad: ≥30% → canal Premium (con captura); 10–29.9% → canal Gratis (solo texto).
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

    /** Porcentaje mínimo de bajada para enviar a algún canal (10%). Por debajo no se notifica. */
    public const MINIMO_BAJADA_PORCENTAJE = 10.0;

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
        $datos = $this->obtenerDatosBajada($producto);

        return $datos['precio_ayer'] ?? null;
    }

    /**
     * Devuelve datos para evaluar la bajada: precio ayer, precio hoy y el registro
     * de historial más reciente (para marcar si ya se notificó y evitar duplicados).
     *
     * @return array{precio_ayer: float, precio_hoy: float, ultimo_registro: HistorialPrecio}|null
     */
    protected function obtenerDatosBajada(Producto $producto): ?array
    {
        $registros = HistorialPrecio::query()
            ->where('producto_id', $producto->id)
            ->orderByDesc('registrado_en')
            ->limit(2)
            ->get();

        if ($registros->count() < 2) {
            return null;
        }

        $ultimo = $registros->first();
        $penultimo = $registros->last();

        $precioAyer = $penultimo->precio_oferta ?? $penultimo->precio_original;
        $precioHoy = $ultimo->precio_oferta ?? $ultimo->precio_original;

        if ($precioAyer === null || $precioHoy === null) {
            return null;
        }

        return [
            'precio_ayer' => (float) $precioAyer,
            'precio_hoy' => (float) $precioHoy,
            'ultimo_registro' => $ultimo,
        ];
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

        $datos = $this->obtenerDatosBajada($producto);
        if ($datos === null) {
            return;
        }

        $precioAyer = $datos['precio_ayer'];
        $precioHoy = $datos['precio_hoy'];
        $ultimoRegistro = $datos['ultimo_registro'];

        // Evitar reenviar la misma bajada cada vez que corre el Job (cada 5 min).
        if ($ultimoRegistro->bajada_notificada_at !== null) {
            return;
        }

        $bajada = $this->calcularPorcentajeBajada($precioAyer, $precioHoy);
        if ($bajada === null || $bajada < self::MINIMO_BAJADA_PORCENTAJE) {
            return;
        }

        try {
            $notificador->enviarOfertaSegunCalidad($producto, $bajada, $precioAyer, $precioHoy);
            $ultimoRegistro->update(['bajada_notificada_at' => now()]);
            Log::info('ProcesarBajadaDePrecioJob: oferta enviada según calidad', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
                'bajada_porcentaje' => round($bajada, 1),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ProcesarBajadaDePrecioJob: fallo al notificar bajada', [
                'producto_id' => $producto->id,
                'sku_tienda' => $producto->sku_tienda,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
