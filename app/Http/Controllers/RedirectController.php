<?php

namespace App\Http\Controllers;

use App\Models\Click;
use App\Models\RedirectLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectController extends Controller
{
    /**
     * Redirige mayoreo.cloud/r/{codigo}: guarda el clic en la DB y redirige al enlace de Admitad (o destino).
     */
    public function show(Request $request, string $codigo): RedirectResponse|Response
    {
        $link = RedirectLink::where('codigo', $codigo)->first();

        if ($link === null) {
            abort(404);
        }

        Click::create([
            'redirect_link_id' => $link->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'clicked_at' => now(),
        ]);

        return redirect()->away($link->url_destino, Response::HTTP_FOUND);
    }
}
