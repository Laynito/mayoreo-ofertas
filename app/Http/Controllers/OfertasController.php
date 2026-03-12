<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Services\AffiliateService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OfertasController extends Controller
{
    public function __construct(
        private AffiliateService $affiliate
    ) {}

    /**
     * Base query para ofertas con enlace de afiliado.
     */
    private function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Producto::query()
            ->whereNotNull('url_producto')
            ->where(function ($q) {
                $q->whereNotNull('url_afiliado')->where('url_afiliado', '!=', '');
            });
    }

    /**
     * Aplica búsqueda: por número (id) o por texto en el nombre.
     */
    private function applySearch(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return;
        }
        if (is_numeric($q)) {
            $query->where('id', (int) $q);
        } else {
            $query->where('nombre', 'like', '%' . $q . '%');
        }
    }

    /**
     * Ofertas del día (entrada por defecto). Productos creados hoy; si no hay, últimas 24 h.
     */
    public function index(Request $request): View
    {
        $query = $this->baseQuery();
        $this->applySearch($query, $request);

        $countHoy = (clone $this->baseQuery())->whereDate('created_at', today())->count();
        if ($countHoy > 0) {
            $query->whereDate('created_at', today());
            $subtitulo = 'Lo que agregamos hoy. Busca por número o nombre.';
        } else {
            $query->where('created_at', '>=', now()->subDay());
            $subtitulo = 'Ofertas de las últimas 24 horas. Busca por número o nombre.';
        }

        $productos = $query->orderByDesc('created_at')->paginate(24, ['*'], 'pagina')->withQueryString();

        return view('ofertas.index', [
            'productos' => $productos,
            'titulo' => 'Ofertas del día',
            'subtitulo' => $subtitulo,
            'esTodas' => false,
            'affiliate' => $this->affiliate,
            'buscar' => $request->input('q', ''),
        ]);
    }

    /**
     * Todas las ofertas (catálogo completo).
     */
    public function todas(Request $request): View
    {
        $query = $this->baseQuery();
        $this->applySearch($query, $request);

        $productos = $query->orderByDesc('created_at')->paginate(24, ['*'], 'pagina')->withQueryString();

        return view('ofertas.index', [
            'productos' => $productos,
            'titulo' => 'Todas las ofertas',
            'subtitulo' => 'Catálogo completo. Busca por número de oferta o nombre.',
            'esTodas' => true,
            'affiliate' => $this->affiliate,
            'buscar' => $request->input('q', ''),
        ]);
    }
}
