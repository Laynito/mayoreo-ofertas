<?php

namespace App\Http\Integrations\Admitad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Lista de cupones del publisher.
 * GET https://api.admitad.com/coupons/
 */
class GetCouponsRequest extends Request
{
    use AcceptsJson;

    protected Method $method = Method::GET;

    public function __construct(
        protected int $limit = 20,
        protected int $offset = 0,
        protected ?string $region = null,
        protected ?string $language = null,
        protected ?int $campaign = null,
        protected ?string $search = null
    ) {}

    public function resolveEndpoint(): string
    {
        return '/coupons/';
    }

    protected function defaultQuery(): array
    {
        $query = [
            'limit' => $this->limit,
            'offset' => $this->offset,
        ];
        if ($this->region !== null) {
            $query['region'] = $this->region;
        }
        if ($this->language !== null) {
            $query['language'] = $this->language;
        }
        if ($this->campaign !== null) {
            $query['campaign'] = $this->campaign;
        }
        if ($this->search !== null && $this->search !== '') {
            $query['search'] = $this->search;
        }
        return $query;
    }
}
