<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\View\View;

class PreciosBajosController extends Controller
{
    public function __invoke(): View
    {
        $productos = Producto::query()
            ->orderByDesc('created_at')
            ->paginate(24);

        return view('precios-bajos', compact('productos'));
    }
}
