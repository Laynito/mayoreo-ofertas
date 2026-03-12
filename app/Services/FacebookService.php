<?php

namespace App\Services;

use App\Models\Marketplace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente para la Graph API de Facebook/Meta (página de ofertas).
 * Usa FB_PAGE_ID y FB_PAGE_ACCESS_TOKEN (config o panel Marketplace).
 */
class FacebookService
{
    private ?string $pageId = null;

    private ?string $accessToken = null;

    private string $graphVersion;

    private string $baseUrl;

    public function __construct()
    {
        $this->graphVersion = config('services.facebook.graph_version', 'v20.0');
        $this->baseUrl = "https://graph.facebook.com/{$this->graphVersion}";
        $this->resolveCredentials();
    }

    private function resolveCredentials(): void
    {
        $this->pageId = config('services.facebook.page_id');
        $this->accessToken = config('services.facebook.page_access_token');

        if (empty($this->pageId) || empty($this->accessToken)) {
            $marketplace = Marketplace::facebookActivo();
            if ($marketplace && is_array($marketplace->configuracion ?? null)) {
                $cfg = $marketplace->configuracion;
                $this->pageId = $this->pageId ?: ($cfg['fb_page_id'] ?? null);
                $this->accessToken = $this->accessToken ?: ($cfg['fb_page_access_token'] ?? null);
            }
        }
    }

    public function getPageId(): ?string
    {
        return $this->pageId;
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->pageId) && ! empty($this->accessToken);
    }

    /**
     * Estado de la conexión con la API: credenciales, token y acceso a la página.
     *
     * @return array{ok: bool, message: string, credentials: bool, token_valid: bool, page_name: string|null, error_code: int|null, error_message: string|null}
     */
    public function getApiStatus(): array
    {
        $result = [
            'ok' => false,
            'message' => '',
            'credentials' => $this->hasCredentials(),
            'token_valid' => false,
            'page_name' => null,
            'error_code' => null,
            'error_message' => null,
        ];

        if (! $this->hasCredentials()) {
            $result['message'] = 'Sin configurar: faltan FB_PAGE_ID o FB_PAGE_ACCESS_TOKEN.';

            return $result;
        }

        $pageInfo = $this->getPageInfo();
        if (isset($pageInfo['error'])) {
            $err = $pageInfo['error'];
            $result['message'] = is_array($err) ? ($err['message'] ?? 'Error de la API') : (string) $err;
            $result['error_code'] = is_array($err) ? ($err['code'] ?? null) : null;
            $result['error_message'] = $result['message'];

            return $result;
        }

        $result['ok'] = true;
        $result['token_valid'] = true;
        $result['page_name'] = $pageInfo['name'] ?? null;
        $result['message'] = 'Conectado. La API responde correctamente.';

        return $result;
    }

    /**
     * Insights de la página (alcance, impresiones, etc.).
     * Requiere permiso read_insights. Solo disponible si la página tiene 100+ me gusta.
     *
     * @param  array<string>  $metrics  ej. ['page_impressions', 'page_engaged_users', 'page_fans']
     * @param  string  $period  day | week | days_28
     * @param  string|null  $since  Y-m-d
     * @param  string|null  $until  Y-m-d
     * @return array{data: array, error?: array}
     */
    public function getInsights(array $metrics, string $period = 'day', ?string $since = null, ?string $until = null): array
    {
        if (! $this->hasCredentials()) {
            return ['data' => [], 'error' => ['message' => 'Faltan credenciales de Facebook (FB_PAGE_ID / FB_PAGE_ACCESS_TOKEN).']];
        }

        $params = [
            'metric' => implode(',', $metrics),
            'period' => $period,
            'access_token' => $this->accessToken,
        ];
        if ($since !== null) {
            $params['since'] = $since;
        }
        if ($until !== null) {
            $params['until'] = $until;
        }

        $response = Http::get("{$this->baseUrl}/{$this->pageId}/insights", $params);

        if (! $response->successful()) {
            Log::warning('Facebook insights request failed', ['status' => $response->status(), 'body' => $response->json()]);

            return ['data' => [], 'error' => $response->json('error', ['message' => $response->body()])];
        }

        return $response->json();
    }

    /**
     * Publicaciones recientes de la página (feed publicado).
     *
     * @param  bool  $withEngagement  Incluir reacciones y comentarios (total_count) para cada post
     * @return array{data: array, error?: array}
     */
    public function getPublishedPosts(int $limit = 25, bool $withEngagement = false): array
    {
        if (! $this->hasCredentials()) {
            return ['data' => [], 'error' => ['message' => 'Faltan credenciales de Facebook.']];
        }

        $fields = 'id,message,created_time,permalink_url,full_picture,attachments{media}';
        if ($withEngagement) {
            $fields .= ',reactions.summary(true),comments.summary(true)';
        }

        $params = [
            'fields' => $fields,
            'limit' => $limit,
            'access_token' => $this->accessToken,
        ];

        $response = Http::get("{$this->baseUrl}/{$this->pageId}/published_posts", $params);

        if (! $response->successful()) {
            Log::warning('Facebook published_posts request failed', ['status' => $response->status(), 'body' => $response->json()]);

            return ['data' => [], 'error' => $response->json('error', ['message' => $response->body()])];
        }

        return $response->json();
    }

    /**
     * Devuelve el total de interacción de un post (reacciones + comentarios).
     * El post debe venir de getPublishedPosts(..., true).
     */
    public static function getPostEngagementCount(array $post): int
    {
        $reactions = (int) ($post['reactions']['summary']['total_count'] ?? 0);
        $comments = (int) ($post['comments']['summary']['total_count'] ?? 0);

        return $reactions + $comments;
    }

    /**
     * Elimina una publicación de la página por su ID.
     */
    public function deletePost(string $postId): array
    {
        if (! $this->hasCredentials()) {
            return ['success' => false, 'error' => 'Faltan credenciales de Facebook.'];
        }

        $response = Http::delete("{$this->baseUrl}/{$postId}", [
            'access_token' => $this->accessToken,
        ]);

        if (! $response->successful()) {
            Log::warning('Facebook delete post failed', ['post_id' => $postId, 'body' => $response->json()]);

            return ['success' => false, 'error' => $response->json('error', ['message' => $response->body()])];
        }

        $body = $response->json();
        $success = ($body['success'] ?? false) === true;

        return ['success' => $success, 'raw' => $body];
    }

    /**
     * Información básica de la página (nombre, etc.).
     */
    public function getPageInfo(): array
    {
        if (! $this->hasCredentials()) {
            return ['error' => 'Faltan credenciales.'];
        }

        $response = Http::get("{$this->baseUrl}/{$this->pageId}", [
            'fields' => 'id,name',
            'access_token' => $this->accessToken,
        ]);

        if (! $response->successful()) {
            return ['error' => $response->json('error', ['message' => $response->body()])];
        }

        return $response->json();
    }
}
