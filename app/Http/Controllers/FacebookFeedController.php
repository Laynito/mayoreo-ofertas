<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * Feed de productos para Meta Commerce Manager (Facebook / Instagram Shops).
 * En Commerce Manager → Catálogos → Agregar fuente → Lista de datos programada → URL de feed.
 * Meta acepta CSV, TSV, XML. Este controlador devuelve CSV con los campos requeridos.
 *
 * @see https://developers.facebook.com/docs/commerce-platform/catalog/fields/
 */
class FacebookFeedController extends Controller
{
    /**
     * Devuelve el catálogo en CSV para Meta.
     * Campos requeridos: id, title, description, availability, condition, price, link, image_link, brand.
     */
    public function __invoke(): Response
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $productos = Producto::query()
            ->whereNotNull('url_producto')
            ->where('url_producto', '!=', '')
            ->orderByDesc('descuento')
            ->get();

        $rows = [];
        $rows[] = [
            'id',
            'title',
            'description',
            'availability',
            'condition',
            'price',
            'link',
            'image_link',
            'brand',
        ];

        foreach ($productos as $p) {
            $link = $p->url_afiliado ?: $p->url_producto;
            $imageLink = $this->normalizeImageUrl($p->url_imagen, $baseUrl);
            if (! $imageLink) {
                continue; // Meta requiere image_link; omitir productos sin imagen accesible
            }

            $rows[] = [
                $this->csvField((string) $p->id),
                $this->csvField(mb_substr($p->nombre ?? '', 0, 200)),
                $this->csvField($this->description($p)),
                'in stock',
                'new',
                $this->price($p),
                $this->csvField($link),
                $this->csvField($imageLink),
                $this->csvField($p->tienda ?? 'Ofertas'),
            ];
        }

        $csv = $this->toCsv($rows);
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="facebook-catalog-' . date('Y-m-d') . '.csv"',
            'Cache-Control'      => 'public, max-age=3600',
        ]);
    }

    private function description(Producto $p): string
    {
        $nombre = $p->nombre ?? '';
        $tienda = $p->tienda ?? '';
        $precio = number_format((float) $p->precio_actual, 2);
        $text = trim("{$nombre}. {$tienda}. Precio oferta: \${$precio} MXN.");
        return mb_substr(preg_replace('/\s+/', ' ', $text), 0, 5000);
    }

    private function price(Producto $p): string
    {
        $valor = number_format((float) $p->precio_actual, 2, '.', '');
        return $valor . ' MXN';
    }

    /** Convierte URL local (localhost/storage/...) a URL pública para que Meta pueda descargar la imagen. */
    private function normalizeImageUrl(?string $url, string $baseUrl): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $url = trim($url);
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host === 'localhost' || $host === '127.0.0.1') {
                $path = parse_url($url, PHP_URL_PATH);
                return $path ? $baseUrl . $path : null;
            }
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return $baseUrl . $url;
        }
        return $baseUrl . '/' . $url;
    }

    private function csvField(string $value): string
    {
        if (str_contains($value, '"') || str_contains($value, ',') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }

    private function toCsv(array $rows): string
    {
        $out = "\xEF\xBB\xBF"; // BOM UTF-8 para Excel/Meta
        foreach ($rows as $row) {
            $out .= implode(',', $row) . "\n";
        }
        return $out;
    }
}
