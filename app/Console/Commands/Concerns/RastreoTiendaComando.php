<?php

namespace App\Console\Commands\Concerns;

use App\Fabrica\RastreadorFabrica;
use App\Jobs\EnviarOfertaTelegramJob;
use App\Jobs\ProcesarBajadaDePrecioJob;
use App\Models\Configuracion;
use App\Models\HistorialPrecio;
use App\Models\Producto;
use App\Services\EstadoMotorService;
use App\Services\NotificadorTelegram;
use Illuminate\Support\Facades\Log;

/**
 * Lógica compartida de rastreo (bulk insert, historial, encolar Telegram).
 * Usado por rastreo:tienda y por rastreo:tienda-calimax, rastreo:tienda-sams, rastreo:tienda-costco.
 */
trait RastreoTiendaComando
{
    /**
     * Ejecuta el rastreo para una tienda (misma lógica que RastrearTienda).
     *
     * @param  array{max?: int|null, notificar_todos?: bool}  $options
     */
    protected function runRastreo(string $tiendaNombre, array $options = []): int
    {
        $max = $options['max'] ?? null;
        $notificarTodos = $options['notificar_todos'] ?? false;

        try {
            $motor = RastreadorFabrica::para($tiendaNombre);
            $tiendaOrigen = RastreadorFabrica::nombreParaBD($tiendaNombre);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        if (app(EstadoMotorService::class)->estaBloqueado($tiendaOrigen)) {
            $this->warn("Motor [{$tiendaOrigen}] marcado como bloqueado. Reactívalo desde Admin → Estado de motores.");
            return 0;
        }

        $this->info("Rastreando ofertas de [{$tiendaOrigen}]...");

        if ($max !== null && $max > 0) {
            $this->info("Límite de productos: {$max} (modo ágil).");
            if (method_exists($motor, 'setLimiteProductos')) {
                $motor->setLimiteProductos($max);
            }
        }

        $this->info('Obteniendo ofertas de la tienda...');
        $items = $motor->recolectarDatos();

        if (empty($items)) {
            $this->warn('No se encontraron productos.');
            return 0;
        }

        if ($max !== null && $max > 0 && count($items) > $max) {
            $items = array_slice($items, 0, $max);
            $this->info("Se tomaron los primeros {$max} productos.");
        }

        if ($tiendaOrigen === 'Calimax') {
            Producto::query()
                ->where('tienda_origen', 'Calimax')
                ->where(function ($q) {
                    $q->where('url_original', 'like', '%myvtex%')
                        ->orWhere('url_original', 'like', '%vtexcommercestable%')
                        ->orWhere('url_original', 'like', '%portal.%');
                })
                ->update(['url_original' => null, 'url_afiliado' => null]);
        }

        $skus = array_column($items, 'sku_tienda');
        $existing = Producto::query()
            ->where('tienda_origen', $tiendaOrigen)
            ->whereIn('sku_tienda', $skus)
            ->get()
            ->keyBy('sku_tienda');

        $now = now();
        $rows = [];
        $historialCandidates = [];

        foreach ($items as $item) {
            $precioOriginal = (float) $item['precio_original'];
            $precioOferta = isset($item['precio_oferta']) ? (float) $item['precio_oferta'] : null;
            if ($precioOferta !== null && $precioOferta >= $precioOriginal) {
                $precioOferta = null;
            }
            $porcentajeAhorro = $this->calculadoraOfertas->calcularPorcentajeAhorro($precioOriginal, $precioOferta);

            $exist = $existing->get($item['sku_tienda']);
            $precioOriginalAnterior = $exist?->precio_original;
            $precioOfertaAnterior = $exist?->precio_oferta;
            $precioCambio = $this->precioCambio(
                $precioOriginalAnterior,
                $precioOfertaAnterior,
                $precioOriginal,
                $precioOferta
            );

            $categoria = $item['categoria_origen'] ?? $exist?->categoria_origen;
            $permite = $exist?->permite_descuento_adicional ?? true;

            $urlOriginal = $item['url_original'] ?? null;
            if ($tiendaOrigen === 'Calimax' && is_string($urlOriginal) && $urlOriginal !== '') {
                $urlLower = strtolower($urlOriginal);
                if (str_contains($urlLower, 'myvtex.com') || str_contains($urlLower, 'vtexcommercestable.com.br') || str_contains($urlLower, 'portal.')) {
                    $urlOriginal = null;
                }
            }

            $rows[] = [
                'tienda_origen' => $tiendaOrigen,
                'sku_tienda' => $item['sku_tienda'],
                'nombre' => $item['nombre'],
                'imagen_url' => $item['imagen_url'] ?? null,
                'precio_original' => $precioOriginal,
                'precio_oferta' => $precioOferta,
                'porcentaje_ahorro' => $porcentajeAhorro,
                'stock_disponible' => $item['stock_disponible'] ?? $exist?->stock_disponible ?? 0,
                'ultima_actualizacion_precio' => $now,
                'url_original' => $urlOriginal,
                'url_afiliado' => $this->generarUrlAfiliado($urlOriginal ?? ''),
                'categoria_origen' => $categoria,
                'permite_descuento_adicional' => $permite,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($exist === null || $precioCambio) {
                $historialCandidates[] = [
                    'sku_tienda' => $item['sku_tienda'],
                    'precio_original' => $precioOriginal,
                    'precio_oferta' => $precioOferta,
                    'porcentaje_ahorro' => $porcentajeAhorro,
                ];
            }
        }

        $this->info('Guardando productos en base de datos (bulk)...');
        Producto::upsert($rows, ['tienda_origen', 'sku_tienda'], [
            'nombre', 'imagen_url', 'precio_original', 'precio_oferta', 'porcentaje_ahorro',
            'stock_disponible', 'ultima_actualizacion_precio', 'url_original', 'url_afiliado',
            'categoria_origen', 'permite_descuento_adicional', 'updated_at',
        ]);

        $productosPorSku = Producto::query()
            ->where('tienda_origen', $tiendaOrigen)
            ->whereIn('sku_tienda', array_column($historialCandidates, 'sku_tienda'))
            ->get()
            ->keyBy('sku_tienda');

        $historialInserts = [];
        foreach ($historialCandidates as $c) {
            $p = $productosPorSku->get($c['sku_tienda']);
            if ($p !== null) {
                $historialInserts[] = [
                    'producto_id' => $p->id,
                    'precio_original' => $c['precio_original'],
                    'precio_oferta' => $c['precio_oferta'],
                    'porcentaje_ahorro' => $c['porcentaje_ahorro'],
                    'registrado_en' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if (! empty($historialInserts)) {
            HistorialPrecio::insert($historialInserts);
            // Encolar detección de bajadas históricas (≥20% vs registro anterior) con captura Browsershot.
            $idsConCambio = array_unique(array_column($historialInserts, 'producto_id'));
            if (! empty($idsConCambio)) {
                ProcesarBajadaDePrecioJob::dispatch($idsConCambio)->delay(now()->addMinutes(1));
            }
        }

        $porcentajeMinimo = Configuracion::porcentajeMinimoNotificacion();
        $requiereDescuentoAdicional = Configuracion::requiereDescuentoAdicional();

        // Lógica de encolado:
        // - Solo productos con descuento real (precio_oferta < precio_original).
        // - Mercado Libre: solo notificar si el descuento calculado es mayor al 15%.
        // - Si "Solo productos con descuento adicional" está activo, se excluyen permite_descuento_adicional = false.
        // - Premium recibe todas las que pasen (0%+); Free según porcentaje mínimo.
        $query = Producto::query()
            ->where('tienda_origen', $tiendaOrigen)
            ->whereColumn('precio_oferta', '<', 'precio_original')
            ->whereNotNull('precio_oferta');
        if ($tiendaOrigen === 'Mercado Libre') {
            $query->whereRaw('precio_original > 0 AND (1 - precio_oferta / precio_original) * 100 > 15');
        }
        if ($requiereDescuentoAdicional) {
            $query->where('permite_descuento_adicional', true);
        }

        if ($notificarTodos) {
            // todos los de la tienda en BD con descuento suficiente
        } else {
            $skusConCambio = array_column($historialCandidates, 'sku_tienda');
            if (! empty($skusConCambio)) {
                $query->whereIn('sku_tienda', $skusConCambio);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $productosParaTelegram = $query->get();
        $encolados = $productosParaTelegram->count();

        $totalConDescuento = Producto::query()
            ->where('tienda_origen', $tiendaOrigen)
            ->whereColumn('precio_oferta', '<', 'precio_original')
            ->whereNotNull('precio_oferta')
            ->count();
        if ($requiereDescuentoAdicional && $totalConDescuento > $encolados) {
            $this->warn("Filtro activo: solo productos con 'permite descuento adicional'. Excluidos: " . ($totalConDescuento - $encolados) . " de {$totalConDescuento}. Desactívalo en Configuración → Notificaciones para enviar todas.");
        }

        if ($productosParaTelegram->isNotEmpty()) {
            try {
                (new NotificadorTelegram)->enviarResumenOfertasPorCanal($productosParaTelegram, $tiendaOrigen);
            } catch (\Throwable $e) {
                Log::warning('RastrearTienda: no se pudo enviar resumen por canal a Telegram', ['mensaje' => $e->getMessage()]);
            }
        }
        // Cola prioritaria: Amazon y Mercado Libre → queue 'high' (worker: --queue=high,default).
        // Amazon con descuento ≥25% → encolar inmediatamente (delay 0); resto espaciado 4 s.
        foreach ($productosParaTelegram as $index => $producto) {
            $cola = in_array($tiendaOrigen, ['Amazon', 'Mercado Libre'], true) ? 'high' : 'default';
            $delaySegundos = $index * 4;
            if ($tiendaOrigen === 'Amazon') {
                $porcentaje = $this->calculadoraOfertas->calcularPorcentajeAhorro(
                    (float) $producto->precio_original,
                    $producto->precio_oferta
                );
                if ($porcentaje !== null && $porcentaje >= 25) {
                    $delaySegundos = 0;
                }
            }
            EnviarOfertaTelegramJob::dispatch($producto)
                ->onQueue($cola)
                ->delay(now()->addSeconds($delaySegundos));
        }

        $this->info('Procesados: ' . count($items) . ' productos.');
        $this->info('Registros en historial de precios: ' . count($historialInserts) . '.');
        $msgEncolados = $notificarTodos
            ? 'Encolados para Telegram (todos con descuento real; Premium recibe todas, Free ≥' . $porcentajeMinimo . '%): ' . $encolados . '.'
            : 'Encolados para Telegram (solo novedades con ≥' . $porcentajeMinimo . '% descuento): ' . $encolados . '.';
        $this->info($msgEncolados);

        try {
            (new NotificadorTelegram)->enviarResumenFinalRastreo($tiendaOrigen, count($items), $encolados);
        } catch (\Throwable $e) {
            Log::warning('RastrearTienda: no se pudo enviar resumen final a Telegram', ['mensaje' => $e->getMessage()]);
        }

        return 0;
    }

    protected function generarUrlAfiliado(string $urlOriginal): ?string
    {
        if ($urlOriginal === '') {
            return null;
        }
        $idAdmitad = config('services.admitad.id', env('ADMITAD_SUBID', ''));
        if ($idAdmitad === '') {
            return $urlOriginal;
        }
        return $this->calculadoraOfertas->urlAfiliadoAdmitad($urlOriginal, $idAdmitad);
    }

    protected function precioCambio(
        mixed $precioOriginalAnterior,
        mixed $precioOfertaAnterior,
        mixed $precioOriginalNuevo,
        mixed $precioOfertaNuevo
    ): bool {
        $originalCambio = (float) ($precioOriginalAnterior ?? 0) !== (float) ($precioOriginalNuevo ?? 0);
        $ofertaCambio = (float) ($precioOfertaAnterior ?? 0) !== (float) ($precioOfertaNuevo ?? 0);
        return $originalCambio || $ofertaCambio;
    }
}
