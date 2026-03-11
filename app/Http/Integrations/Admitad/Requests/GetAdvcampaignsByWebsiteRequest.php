<?php

namespace App\Http\Integrations\Admitad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Lista de programas de afiliados para un espacio publicitario (website).
 * GET https://api.admitad.com/advcampaigns/website/{w_id}/
 * Requiere scope advcampaigns_for_website. Aquí sí aplica connection_status (active = solo conectados).
 */
class GetAdvcampaignsByWebsiteRequest extends Request
{
    use AcceptsJson;

    protected Method $method = Method::GET;

    public function __construct(
        protected int $websiteId,
        protected int $limit = 20,
        protected int $offset = 0,
        protected ?string $connectionStatus = null,
        protected ?string $language = null,
        protected ?string $hasTool = null
    ) {}

    public function resolveEndpoint(): string
    {
        return "/advcampaigns/website/{$this->websiteId}/";
    }

    protected function defaultQuery(): array
    {
        $query = [
            'limit' => $this->limit,
            'offset' => $this->offset,
        ];
        if ($this->connectionStatus !== null) {
            $query['connection_status'] = $this->connectionStatus;
        }
        if ($this->language !== null) {
            $query['language'] = $this->language;
        }
        if ($this->hasTool !== null) {
            $query['has_tool'] = $this->hasTool;
        }
        return $query;
    }
}
