<?php

namespace App\Http\Integrations\Admitad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Genera enlaces de afiliado (deeplinks) para una URL o varias.
 * GET https://api.admitad.com/deeplink/{w_id}/advcampaign/{c_id}/?ulp=url1&ulp=url2
 * Requiere scope deeplink_generator.
 */
class GetDeeplinkRequest extends Request
{
    use AcceptsJson;

    protected Method $method = Method::GET;

    public function __construct(
        protected int $websiteId,
        protected int $campaignId,
        /** @var array<int, string> URLs a convertir (máx 200) */
        protected array $urls,
        protected ?string $subid = null,
        protected ?string $subid1 = null,
        protected ?string $subid2 = null,
        protected ?string $subid3 = null,
        protected ?string $subid4 = null
    ) {}

    public function resolveEndpoint(): string
    {
        $path = "/deeplink/{$this->websiteId}/advcampaign/{$this->campaignId}/";
        $urls = array_slice($this->urls, 0, 200);
        $params = array_map(fn (string $u): string => 'ulp=' . rawurlencode($u), $urls);
        if ($this->subid !== null) {
            $params[] = 'subid=' . rawurlencode($this->subid);
        }
        if ($this->subid1 !== null) {
            $params[] = 'subid1=' . rawurlencode($this->subid1);
        }
        if ($this->subid2 !== null) {
            $params[] = 'subid2=' . rawurlencode($this->subid2);
        }
        if ($this->subid3 !== null) {
            $params[] = 'subid3=' . rawurlencode($this->subid3);
        }
        if ($this->subid4 !== null) {
            $params[] = 'subid4=' . rawurlencode($this->subid4);
        }
        return $params !== [] ? $path . '?' . implode('&', $params) : $path;
    }

    protected function defaultQuery(): array
    {
        return [];
    }
}
