<?php

namespace App\Http\Integrations\Admitad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Lista de programas de afiliados (advcampaigns).
 * GET https://api.admitad.com/advcampaigns/
 */
class GetAdvcampaignsRequest extends Request
{
    use AcceptsJson;

    protected Method $method = Method::GET;

    public function __construct(
        protected int $limit = 20,
        protected int $offset = 0,
        protected ?int $website = null,
        protected ?string $language = null,
        protected ?string $connectionStatus = null,
        protected ?string $hasTool = null
    ) {}

    public function resolveEndpoint(): string
    {
        return '/advcampaigns/';
    }

    protected function defaultQuery(): array
    {
        $query = [
            'limit' => $this->limit,
            'offset' => $this->offset,
        ];
        if ($this->website !== null) {
            $query['website'] = $this->website;
        }
        if ($this->language !== null) {
            $query['language'] = $this->language;
        }
        if ($this->connectionStatus !== null) {
            $query['connection_status'] = $this->connectionStatus;
        }
        if ($this->hasTool !== null) {
            $query['has_tool'] = $this->hasTool;
        }
        return $query;
    }
}
