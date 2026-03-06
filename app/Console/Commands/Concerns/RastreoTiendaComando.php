<?php

namespace App\Console\Commands\Concerns;

use App\Events\PrecioBajo;
use App\Fabrica\RastreadorFabrica;
use App\Jobs\EnviarOfertaTelegramJob;
use App\Jobs\ProcesarBajadaDePrecioJob;
use App\Models\Configuracion;
use App\Models\HistorialPrecio;
use App\Models\Producto;
use App\Models\Tienda;
use App\Services\AdmitadService;
use App\Services\EstadoMotorService;
use App\Services\NormalizadorEnlacesAfiliadoService;
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
     * @param  int|null  $encolados  Si se pasa, se rellena con el número de ofertas encoladas para Telegram.
     * @param  int  $delayOffsetGlobal  Desplazamiento global para el delay (rastreo:todas unifica una sola cola de ofertas).
     */
    protected function runRastreo(string $tiendaNombre, array $options = [], ?int &$encolados = null, int $delayOffsetGlobal = 0): int
    {
        $max = $options['max'] ?? null;
        $notificarTodos = $options['notificar_todos'] ?? false;
        $minDiscount = (int) ($options['min_discount'] ?? 10);

        try {
            $motor = RastreadorFabrica::para($tiendaNombre);
            $tiendaOrigen = RastreadorFabrica::nombreParaBD($tiendaNombre);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $tiendaModel = Tienda::query()->where('nombre', $tiendaOrigen)->first();
        if ($tiendaModel !== null && ! $tiendaModel->activo) {
            $this->warn("La tienda [{$tiendaOrigen}] está pausada en Administración → Tiendas. Actívala para rastrear.");
            return 0;
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
        try {
            $items = $motor->recolectarDatos();
        } catch (\Exception $e) {
            Log::error('RastreoTiendaComando: fallo al recolectar datos del motor.', [
                'tienda' => $tiendaOrigen,
                'mensaje' => $e->getMessage(),
                'excepcion' => get_class($e),
            ]);
            $this->error('No se pudo obtener las ofertas de la tienda.');
            $this->line('Detalle: ' . $e->getMessage());
            $this->warn('El comando finalizó sin actualizar la base de datos. Revisa la conexión (proxy, API) o los logs.');

            return 1;
        }

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
            // Mercado Libre: siempre permitir descuento adicional en rastreo para que el filtro no excluya ofertas.
            if ($tiendaOrigen === 'Mercado Libre') {
                $permite = true;
            }

            $urlCruda = $item['url_original'] ?? null;
            if ($tiendaOrigen === 'Calimax' && is_string($urlCruda) && $urlCruda !== '') {
                $urlLower = strtolower($urlCruda);
                if (str_contains($urlLower, 'myvtex.com') || str_contains($urlLower, 'vtexcommercestable.com.br') || str_contains($urlLower, 'portal.')) {
                    $urlCruda = null;
                }
            }

            // Fábrica de links: por tienda se genera la URL final de afiliado y se guarda en url_original (la que se envía a Telegram).
            $urlOriginal = $this->aplicarFabricaEnlacesAfiliado($tiendaOrigen, $urlCruda);

            $sinPrecio = $precioOriginal <= 0 && $precioOferta === null;
            $noDisponible = is_string($item['nombre'] ?? '') && stripos($item['nombre'], 'No disponible') !== false;
            $activo = ! $sinPrecio && ! $noDisponible;

            $rows[] = [
                'tienda_id' => $tiendaModel?->id,
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
                'url_afiliado' => null,
                'affiliate_url' => $urlOriginal,
                'categoria_origen' => $categoria,
                'permite_descuento_adicional' => $permite,
                'activo' => $activo,
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
            'tienda_id', 'nombre', 'imagen_url', 'precio_original', 'precio_oferta', 'porcentaje_ahorro',
            'stock_disponible', 'ultima_actualizacion_precio', 'url_original', 'url_afiliado', 'affiliate_url',
            'categoria_origen', 'permite_descuento_adicional', 'activo', 'updated_at',
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
        }

        $porcentajeMinimo = Configuracion::porcentajeMinimoNotificacion();
        $requiereDescuentoAdicional = Configuracion::requiereDescuentoAdicional();

        // Lógica de encolado:
        // - Solo productos con descuento real (precio_oferta < precio_original).
        // - Mercado Libre: solo notificar si el descuento es >= min_discount (por defecto 15%; --min-discount=N para cambiar).
        // - Si "Solo productos con descuento adicional" está activo, se excluyen permite_descuento_adicional = false.
        // Todas las que pasen el filtro se encolan al canal principal.
        $query = Producto::query()
            ->where('tienda_origen', $tiendaOrigen)
            ->where('activo', true)
            ->whereColumn('precio_oferta', '<', 'precio_original')
            ->whereNotNull('precio_oferta');
        if ($tiendaOrigen === 'Mercado Libre') {
            $query->whereRaw('precio_original > 0 AND (1 - precio_oferta / precio_original) * 100 >= ?', [$minDiscount]);
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

        $query->orderByDesc('porcentaje_ahorro');
        $productosParaTelegram = $query->get();
        $encolados = $productosParaTelegram->count();

        $totalConDescuento = Producto::query()
            ->where('tienda_origen', $tiendaOrigen)
            ->whereColumn('precio_oferta', '<', 'precio_original')
            ->whereNotNull('precio_oferta')
            ->count();

        $soloNovedadesSinCambios = ! $notificarTodos && empty(array_column($historialCandidates, 'sku_tienda'));
        if ($soloNovedadesSinCambios && $encolados === 0 && $totalConDescuento > 0) {
            $this->warn("Encolados: 0 (solo se encolan novedades con cambio de precio; este rastreo no tuvo cambios). Usa --notificar-todos para encolar todas las ofertas con descuento.");
        } elseif ($requiereDescuentoAdicional && $totalConDescuento > $encolados && ! $soloNovedadesSinCambios) {
            $this->warn("Filtro activo: solo productos con 'permite descuento adicional'. Excluidos: " . ($totalConDescuento - $encolados) . " de {$totalConDescuento}. Desactívalo en Configuración → Notificaciones para enviar todas.");
        }

        // Novedades (solo con cambio de precio): evento PrecioBajo → listeners monetizan y encolan. --notificar-todos: encolado directo.
        $delayInicial = (int) config('services.telegram.delay_inicial_ofertas_segundos', 10);
        $delayEntreOfertas = (int) config('services.telegram.delay_entre_ofertas_segundos', 15);
        if ($notificarTodos) {
            $cola = in_array($tiendaOrigen, ['Amazon', 'Mercado Libre'], true) ? 'high' : 'default';
            foreach ($productosParaTelegram as $index => $producto) {
                $delaySegundos = $delayInicial + (($delayOffsetGlobal + $index) * $delayEntreOfertas);
                EnviarOfertaTelegramJob::dispatch($producto)
                    ->onQueue($cola)
                    ->delay(now()->addSeconds($delaySegundos));
            }
        } else {
            foreach ($productosParaTelegram as $index => $producto) {
                $delaySegundos = $delayInicial + (($delayOffsetGlobal + $index) * $delayEntreOfertas);
                event(new PrecioBajo($producto, $delaySegundos));
            }
        }
        if ($encolados !== null) {
            $encolados = $productosParaTelegram->count();
        }

        // Bajadas históricas: solo productos con cambio de precio que NO se enviaron ya como oferta (evita duplicado).
        if (! empty($historialInserts)) {
            $idsConCambio = array_unique(array_column($historialInserts, 'producto_id'));
            $idsYaEnviados = $productosParaTelegram->pluck('id')->all();
            $idsSoloBajada = array_values(array_diff($idsConCambio, $idsYaEnviados));
            if (! empty($idsSoloBajada)) {
                ProcesarBajadaDePrecioJob::dispatch($idsSoloBajada)->delay(now()->addMinutes(1));
            }
        }

        $this->info('Procesados: ' . count($items) . ' productos.');
        $this->info('Registros en historial de precios: ' . count($historialInserts) . '.');
        $msgEncolados = $notificarTodos
            ? 'Encolados para Telegram: ' . $encolados . ' ofertas.'
            : 'Encolados para Telegram (solo novedades ≥' . $porcentajeMinimo . '%): ' . $encolados . ' ofertas.';
        $this->info($msgEncolados);
        if ($encolados === 0 && count($items) > 0) {
            $this->warn("Cero ofertas encoladas para [{$tiendaOrigen}]. Para enviar todas las ofertas con descuento: php artisan rastreo:tienda \"{$tiendaOrigen}\" --notificar-todos");
        }

        return 0;
    }

    /**
     * Fábrica de links por red de afiliados: devuelve la URL final que se guarda en url_original y se envía a Telegram.
     * - Mercado Libre: normalizador (limpia rastreo ajeno, fuerza &micosmtics=187001804).
     * - Amazon: normalizador (inyecta tag=micosmtics-20 al final).
     * - Walmart, Sam's Club, Costco: Admitad deeplink (ulp=URL codificada).
     * - Resto: URL sin modificar.
     */
    protected function aplicarFabricaEnlacesAfiliado(string $tiendaOrigen, ?string $urlCruda): ?string
    {
        if ($urlCruda === null || $urlCruda === '') {
            return null;
        }

        $normalizador = app(NormalizadorEnlacesAfiliadoService::class);

        if ($tiendaOrigen === 'Mercado Libre' && str_contains($urlCruda, 'mercadolibre.com')) {
            return $normalizador->normalizarUrlMercadoLibre($urlCruda);
        }

        if ($tiendaOrigen === 'Amazon' && (str_contains($urlCruda, 'amazon.') || str_contains($urlCruda, 'amzn.'))) {
            return $normalizador->normalizarUrlAmazon($urlCruda);
        }

        if (in_array($tiendaOrigen, ['Walmart', 'Sams Club', 'Costco'], true)) {
            return app(AdmitadService::class)->generarDeeplink($urlCruda);
        }

        return $urlCruda;
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
