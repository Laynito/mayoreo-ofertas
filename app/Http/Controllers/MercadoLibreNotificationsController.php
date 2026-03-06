<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\MercadoLibreWebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recibidor de notificaciones de Mercado Libre.
 * URL a configurar en el panel de desarrolladores: https://mayoreo.cloud/api/mercado-libre/notifications
 * ML envía POST con topic, resource, application_id, sent_time, etc. Debe responderse 200 en menos de 500 ms.
 */
class MercadoLibreNotificationsController extends Controller
{
    /**
     * Respuesta para peticiones GET (p. ej. al abrir la URL en el navegador).
     * Evita "Method Not Allowed" y aclara que el webhook solo acepta POST.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'message' => 'Este endpoint es para el webhook de Mercado Libre. Solo se aceptan peticiones POST.',
            'url' => 'https://developers.mercadolibre.com',
        ], 200);
    }

    /**
     * Acepta los avisos POST de Mercado Libre. Registra el ping, invalida token si aplica y responde 200 de inmediato.
     */
    public function handle(Request $request): JsonResponse
    {
        $topic = $request->input('topic') ?? 'desconocido';
        $resource = $request->input('resource');
        $sentTime = $request->input('sent_time');

        MercadoLibreWebhookLog::create([
            'topic' => $topic,
            'resource' => is_string($resource) ? $resource : null,
            'sent_time' => is_scalar($sentTime) ? (string) $sentTime : null,
            'received_at' => now(),
        ]);

        Log::info('[ML-WEBHOOK] Topic ' . $topic . (is_string($resource) && $resource !== '' ? ' | Resource ' . $resource : ''));

        $this->invalidarTokenSiRevocado($topic);

        return response()->json(['status' => 'received'], 200);
    }

    /**
     * Si ML notifica que se revocó la autorización/token, marca el token como expirado
     * para que el Centro de Control avise y el usuario re-conecte.
     */
    private function invalidarTokenSiRevocado(string $topic): void
    {
        $topicLower = strtolower($topic);
        if (str_contains($topicLower, 'deauthorization') || str_contains($topicLower, 'revoke')) {
            Configuracion::guardar(Configuracion::CLAVE_ML_EXPIRES_AT, '0');
            Log::info('Webhook ML: token/authorization revocado (topic: ' . $topic . '). CLAVE_ML_EXPIRES_AT marcado como pasado.');
        }
    }
}
