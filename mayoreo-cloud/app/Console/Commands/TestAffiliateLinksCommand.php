<?php

namespace App\Console\Commands;

use App\Models\Producto;
use App\Services\AffiliateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestAffiliateLinksCommand extends Command
{
    protected $signature = 'app:test-affiliate-links
                            {url? : URL de producto ML (opcional; si no se pasa, usa el primer producto de la BD)}';

    protected $description = 'Genera el enlace de afiliado canónico y comprueba si la URL responde correctamente.';

    public function handle(AffiliateService $affiliate): int
    {
        $urlProducto = $this->argument('url');

        if (! $urlProducto) {
            $producto = Producto::query()->whereNotNull('url_producto')->first();
            if (! $producto) {
                $this->warn('No hay productos en la BD. Pasa una URL como argumento:');
                $this->line('  php artisan app:test-affiliate-links "https://www.mercadolibre.com.mx/p/MLM123"');
                return self::FAILURE;
            }
            $urlProducto = $producto->url_producto;
            $this->info('Usando primer producto de la BD: ' . $producto->nombre);
        }

        $link = $affiliate->getCanonicalAffiliateLink($urlProducto);
        $this->newLine();
        $this->info('Enlace canónico de afiliado:');
        $this->line($link);
        $this->newLine();

        // Comprobar que los parámetros son los esperados
        $expectedAffid = config('services.mercadolibre.affid');
        $expectedTool = config('services.mercadolibre.app_id');
        $hasAffid = str_contains($link, 'affid=' . $expectedAffid);
        $hasTool = str_contains($link, 'matt_tool=' . $expectedTool);
        $hasWord = str_contains($link, 'matt_word=mayoreo_cloud');

        $this->info('Parámetros en la URL:');
        $this->table(
            ['Parámetro', 'Valor esperado', '¿Presente?'],
            [
                ['matt_tool', $expectedTool ?? '—', $hasTool ? 'Sí' : 'No'],
                ['matt_word', 'mayoreo_cloud', $hasWord ? 'Sí' : 'No'],
                ['affid', (string) $expectedAffid, $hasAffid ? 'Sí' : 'No'],
            ]
        );

        if (! $hasAffid || ! $hasTool) {
            $this->warn('Revisa ML_APP_ID y ML_AFFID en .env y ejecuta: php artisan config:clear');
        }

        $this->info('Comprobando si la URL responde...');
        try {
            $response = Http::timeout(15)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($link);

            $status = $response->status();
            $ok = $response->successful();

            if ($ok) {
                $this->info("Respuesta: HTTP {$status} — el enlace responde correctamente.");
            } else {
                $this->warn("La URL devolvió HTTP {$status}. Revisa el enlace manualmente en el navegador.");
            }
        } catch (\Throwable $e) {
            $this->warn('No se pudo comprobar la URL: ' . $e->getMessage());
            $this->line('Abre el enlace en el navegador para verificar que funciona.');
        }

        $this->newLine();
        return self::SUCCESS;
    }
}
