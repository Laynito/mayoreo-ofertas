<?php

namespace App\Services;

use App\Http\Integrations\Admitad\AdmitadConnector;
use App\Http\Integrations\Admitad\Requests\GetAdvcampaignsByWebsiteRequest;
use App\Http\Integrations\Admitad\Requests\GetAdvcampaignsRequest;
use App\Http\Integrations\Admitad\Requests\GetCouponsRequest;
use App\Http\Integrations\Admitad\Requests\GetDeeplinkRequest;
use App\Http\Integrations\Admitad\Requests\GetWebsitesRequest;
use App\Http\Integrations\Admitad\Requests\PostShortlinkRequest;
use Illuminate\Support\Facades\Log;

class AdmitadService
{
    public function __construct(
        protected AdmitadConnector $connector
    ) {}

    /**
     * Lista programas de afiliados (advcampaigns).
     *
     * @param  string|null  $connectionStatus  active = solo conectados, pending = pendientes, declined = rechazados
     * @return array{results: array, _meta: array}
     */
    public function getPrograms(int $limit = 20, int $offset = 0, ?int $websiteId = null, ?string $language = null, ?string $connectionStatus = null): array
    {
        $response = $this->connector->send(new GetAdvcampaignsRequest($limit, $offset, $websiteId, $language, $connectionStatus));
        if (! $response->successful()) {
            Log::warning('AdmitadService::getPrograms failed', ['status' => $response->status(), 'body' => $response->body()]);
            return ['results' => [], '_meta' => ['count' => 0, 'limit' => $limit, 'offset' => $offset]];
        }
        return $response->json();
    }

    /**
     * Lista programas de afiliados para un espacio publicitario. Aquí connection_status sí filtra (active = solo conectados).
     * Requiere scope advcampaigns_for_website.
     *
     * @return array{results: array, _meta: array}
     */
    public function getProgramsByWebsite(int $websiteId, int $limit = 20, int $offset = 0, ?string $connectionStatus = null, ?string $language = null): array
    {
        $response = $this->connector->send(new GetAdvcampaignsByWebsiteRequest($websiteId, $limit, $offset, $connectionStatus, $language));
        if (! $response->successful()) {
            Log::warning('AdmitadService::getProgramsByWebsite failed', ['status' => $response->status(), 'body' => $response->body()]);
            return ['results' => [], '_meta' => ['count' => 0, 'limit' => $limit, 'offset' => $offset]];
        }
        return $response->json();
    }

    /**
     * Lista cupones del publisher.
     *
     * @return array{results: array, _meta: array}
     */
    public function getCoupons(int $limit = 20, int $offset = 0, ?string $region = null, ?string $language = null, ?int $campaign = null, ?string $search = null): array
    {
        $response = $this->connector->send(new GetCouponsRequest($limit, $offset, $region, $language, $campaign, $search));
        if (! $response->successful()) {
            Log::warning('AdmitadService::getCoupons failed', ['status' => $response->status(), 'body' => $response->body()]);
            return ['results' => [], '_meta' => ['count' => 0, 'limit' => $limit, 'offset' => $offset]];
        }
        return $response->json();
    }

    /**
     * Lista espacios publicitarios (websites) del publisher. Necesario para obtener website_id del deeplink.
     *
     * @return array<int, array{id: int, name: string, site_url: string, ...}>
     */
    public function getWebsites(): array
    {
        $response = $this->connector->send(new GetWebsitesRequest());
        if (! $response->successful()) {
            Log::warning('AdmitadService::getWebsites failed', ['status' => $response->status(), 'body' => $response->body()]);
            return [];
        }
        $data = $response->json();
        return is_array($data) ? $data : [];
    }

    /**
     * Genera enlaces de afiliado (deeplinks) para las URLs dadas.
     * Requiere website_id (espacio publicitario) y campaign_id (programa).
     *
     * @param  array<int, string>  $urls
     * @return array<int, array{link: string, is_affiliate_product: bool|null}>
     */
    public function generateDeeplinks(int $websiteId, int $campaignId, array $urls, ?string $subid = null): array
    {
        if (empty($urls)) {
            return [];
        }
        $request = new GetDeeplinkRequest($websiteId, $campaignId, $urls, $subid);
        $response = $this->connector->send($request);
        if (! $response->successful()) {
            Log::warning('AdmitadService::generateDeeplinks failed', ['status' => $response->status(), 'body' => $response->body()]);
            return [];
        }
        $data = $response->json();
        return is_array($data) ? $data : [];
    }

    /**
     * Acorta un enlace de Admitad (debe ser de dominio admitad, p. ej. https://ad.admitad.com/...).
     */
    public function shortenLink(string $link): ?string
    {
        $response = $this->connector->send(new PostShortlinkRequest($link));
        if (! $response->successful()) {
            Log::warning('AdmitadService::shortenLink failed', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }
        $data = $response->json();
        return $data['short_link'] ?? null;
    }
}
