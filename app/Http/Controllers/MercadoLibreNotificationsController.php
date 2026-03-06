<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recibidor de notificaciones de Mercado Libre.
 * URL a configurar en el panel de desarrolladores: https://mayoreo.cloud/api/mercado-libre/notifications
 * ML envía POST con topic, resource, application_id, etc. Debe responderse 200 en menos de 500 ms.
 */
class MercadoLibreNotificationsController extends Controller
{
    /**
     * Acepta los avisos POST de Mercado Libre (ofertas, ítems, mensajes, etc.).
     * Responde 200 inmediatamente; el procesamiento pesado puede encolarse.
     */
    public function handle(Request $request): JsonResponse
    {
        $topic = $request->input('topic');
        $resource = $request->input('resource');
        $applicationId = $request->input('application_id');

        Log::info('MercadoLibreNotificationsController: notificación recibida', [
            'topic' => $topic,
            'resource' => $resource,
            'application_id' => $applicationId,
        ]);

        return response()->json(['received' => true], 200);
    }
}
