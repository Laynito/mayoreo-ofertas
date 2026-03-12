<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Services\AffiliateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutController extends Controller
{
    /**
     * Redirige al enlace de afiliado del producto y registra el click (ML, Coppel, etc.).
     */
    public function __invoke(Request $request, int $producto, AffiliateService $affiliate): RedirectResponse|JsonResponse
    {
        $producto = Producto::query()->find($producto);

        if (! $producto || ! $producto->url_producto) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Producto no encontrado'], 404);
            }
            abort(404, 'Producto no encontrado');
        }

        $slug = $affiliate->getSlugFromTienda($producto->tienda, $producto->url_producto);

        DB::table('afiliado_clicks')->insert([
            'producto_id' => $producto->id,
            'marketplace_slug' => $slug,
            'created_at' => now(),
        ]);

        $url = trim((string) $producto->url_afiliado) !== ''
            ? $producto->url_afiliado
            : $affiliate->getAffiliateLinkForProduct($producto->url_producto, $producto->tienda);

        return redirect()->away($url, 302, ['Referrer-Policy' => 'strict-origin-when-cross-origin']);
    }
}
