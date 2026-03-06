<?php

namespace App\Console\Commands;

use App\Models\Producto;
use App\Services\MercadoLibreImagenApiService;
use App\Services\MercadoLibreShortUrlService;
use Illuminate\Console\Command;

/**
 * Rellena imagen_url de productos ML creados recientemente sin imagen, usando la API (items/products).
 */
class MercadoLibreRellenarImagen extends Command
{
    protected $signature = 'ml:rellenar-imagen
                            {--dias=1 : Días hacia atrás (solo productos creados desde hace N días)}
                            {--dry-run : Solo listar qué productos se actualizarían, sin guardar}';

    protected $description = 'Rellena imagen_url de productos ML sin imagen usando la API de Mercado Libre';

    public function handle(): int
    {
        $dias = (int) $this->option('dias');
        $dryRun = (bool) $this->option('dry-run');
        $desde = now()->subDays($dias)->startOfDay();

        $productos = Producto::where('tienda_origen', 'Mercado Libre')
            ->where(function ($q): void {
                $q->whereNull('imagen_url')->orWhere('imagen_url', '');
            })
            ->where('created_at', '>=', $desde)
            ->get();

        if ($productos->isEmpty()) {
            $this->info('No hay productos de Mercado Libre sin imagen desde ' . $desde->toDateTimeString() . '.');

            return self::SUCCESS;
        }

        $this->info('Productos a procesar: ' . $productos->count() . ($dryRun ? ' (dry-run, no se guardará)' : ''));

        $actualizados = 0;
        $sinId = 0;
        $sinImagen = 0;
        foreach ($productos as $producto) {
            $itemId = MercadoLibreShortUrlService::extraerItemId($producto->url_original ?? '', $producto->sku_tienda);
            if ($itemId === null || $itemId === '') {
                $sinId++;
                $this->line("  [sin ID] {$producto->nombre} (url: " . ($producto->url_original ?? '') . ')');
                continue;
            }
            $imagenUrl = MercadoLibreImagenApiService::getImagenUrl($producto);
            if ($imagenUrl === null || $imagenUrl === '') {
                $sinImagen++;
                $this->line("  [API sin imagen] {$producto->nombre} (ID: {$itemId})");
                continue;
            }
            if (! $dryRun) {
                $producto->imagen_url = $imagenUrl;
                $producto->save();
            }
            $actualizados++;
            $this->line('  [OK] ' . $producto->nombre . ' → ' . $imagenUrl);
        }

        $this->newLine();
        $this->info("Resumen: {$actualizados} actualizados, {$sinId} sin ID extraíble, {$sinImagen} sin imagen en API." . ($dryRun ? ' (dry-run)' : ''));
        return self::SUCCESS;
    }
}
