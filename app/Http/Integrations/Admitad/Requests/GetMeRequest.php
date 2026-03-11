<?php

namespace App\Http\Integrations\Admitad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Información del publisher autenticado.
 * GET https://api.admitad.com/me/
 */
class GetMeRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/me/';
    }
}
