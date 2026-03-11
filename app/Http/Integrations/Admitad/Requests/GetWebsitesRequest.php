<?php

namespace App\Http\Integrations\Admitad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Lista de espacios publicitarios (websites) del publisher.
 * GET https://api.admitad.com/websites/v2/
 */
class GetWebsitesRequest extends Request
{
    use AcceptsJson;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/websites/v2/';
    }
}
