<?php

namespace App\Console\Commands;

use App\Fabrica\RastreadorFabrica;
use App\Jobs\EnviarOfertaTelegramJob;
use App\Models\Configuracion;
use App\Models\HistorialPrecio;
use App\Models\Producto;
use App\Services\CalculadoraOfertas;
use App\Services\NotificadorTelegram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RastrearTienda extends Command
{
    protected $signature = 'rastreo:tienda
                            {tienda : Nombre de la tienda (ej. Coppel, Walmart)}
                            {--max= : Procesar solo los primeros N productos (ej. 10 o 20) para agilizar}
                            {--notificar-todos : Encolar a Telegram todos los que tengan descuento real (precio_oferta < precio_original), no solo novedades}';

    protected $description = 'Rastrea ofertas de una tienda, actualiza productos y registra historial de precios';

    public function __construct(
        protected CalculadoraOfertas $calculadoraOfertas
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tiendaArgumento = $this->argument('tienda');

        try {
            $motor = RastreadorFabrica::para($tiendaArgumento);
            $tiendaOrigen = RastreadorFabrica::nombreParaBD($tiendaArgumento);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Rastreando ofertas de [{$tiendaOrigen}]...");

        if (strcasecmp($tiendaOrigen, 'Coppel') === 0) {
            try {
                (new NotificadorTelegram)->enviarMensajeSimple('Iniciando rastreo de Coppel...');
            } catch (\Throwable $e) {
                Log::warning('RastrearTienda: no se pudo enviar mensaje inicial a Telegram', ['mensaje' => $e->getMessage()]);
            }
        }

        $max = $this->option('max') !== null ? (int) $this->option('max') : null;
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

            return self::SUCCESS;
        }

        if ($max !== null && $max > 0 && count($items) > $max) {
            $items = array_slice($items, 0, $max);
            $this->info("Se tomaron los primeros {$max} productos.");
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
            $porcentajeAhorro = $this->calculadoraOfertas->calcularPorcentajeAhorro(
                (float) $item['precio_original'],
                $item['precio_oferta'] ?? null
            );
            $exist = $existing->get($item['sku_tienda']);
            $precioOriginalAnterior = $exist?->precio_original;
            $precioOfertaAnterior = $exist?->precio_oferta;
            $precioCambio = $this->precioCambio(
                $precioOriginalAnterior,
                $precioOfertaAnterior,
                $item['precio_original'],
                $item['precio_oferta'] ?? null
            );

            $categoria = $item['categoria_origen'] ?? $exist?->categoria_origen;
            $permite = $exist?->permite_descuento_adicional ?? true;

            $rows[] = [
                'tienda_origen' => $tiendaOrigen,
                'sku_tienda' => $item['sku_tienda'],
                'nombre' => $item['nombre'],
                'imagen_url' => $item['imagen_url'] ?? null,
                'precio_original' => $item['precio_original'],
                'precio_oferta' => $item['precio_oferta'] ?? null,
                'porcentaje_ahorro' => $porcentajeAhorro,
                'stock_disponible' => $item['stock_disponible'] ?? $exist?->stock_disponible ?? 0,
                'ultima_actualizacion_precio' => $now,
                'url_original' => $item['url_original'] ?? null,
                'url_afiliado' => $this->generarUrlAfiliado($item['url_original'] ?? ''),
                'categoria_origen' => $categoria,
                'permite_descuento_adicional' => $permite,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($exist === null || $precioCambio) {
                $historialCandidates[] = [
                    'sku_tienda' => $item['sku_tienda'],
                    'precio_original' => $item['precio_original'],
                    'precio_oferta' => $item['precio_oferta'] ?? null,
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
        }

        // Encolar Telegram: con --notificar-todos todos los de la tienda en BD que cumplan criterio; si no, solo novedades de esta ejecución
        $porcentajeMinimo = Configuracion::porcentajeMinimoNotificacion();
        $requiereDescuentoAdicional = Configuracion::requiereDescuentoAdicional();

        $query = Producto::query()
            ->where('tienda_origen', $tiendaOrigen)
            ->whereColumn('precio_oferta', '<', 'precio_original')
            ->where('porcentaje_ahorro', '>=', $porcentajeMinimo)
            ->whereNotNull('precio_oferta');
        if ($requiereDescuentoAdicional) {
            $query->where('permite_descuento_adicional', true);
        }

        if ($this->option('notificar-todos')) {
            // Todos los productos de la tienda en BD que tengan descuento suficiente (no solo los de esta ejecución)
        } else {
            $skusConCambio = array_column($historialCandidates, 'sku_tienda');
            if (! empty($skusConCambio)) {
                $query->whereIn('sku_tienda', $skusConCambio);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $productosParaTelegram = $query->get();

        $encolados = 0;
        foreach ($productosParaTelegram as $index => $producto) {
            EnviarOfertaTelegramJob::dispatch($producto)
                ->delay(now()->addSeconds($index * 5));
            $encolados++;
        }

        $this->info('Procesados: ' . count($items) . ' productos.');
        $this->info('Registros en historial de precios: ' . count($historialInserts) . '.');
        $msgEncolados = $this->option('notificar-todos')
            ? 'Encolados para Telegram (todos con descuento real, ≥' . $porcentajeMinimo . '%): ' . $encolados . '.'
            : 'Encolados para Telegram (solo novedades con ≥' . $porcentajeMinimo . '% descuento): ' . $encolados . '.';
        $this->info($msgEncolados);

        return self::SUCCESS;
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
