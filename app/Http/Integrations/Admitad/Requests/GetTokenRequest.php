<?php

namespace App\Http\Integrations\Admitad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Contracts\Body\HasBody;
use Saloon\Traits\Body\HasFormBody;

/**
 * Obtiene access_token de Admitad (OAuth 2.0 client_credentials).
 * Documentación: https://developers.admitad.com/knowledge-base/article/client-authorization_2
 */
class GetTokenRequest extends Request implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/token/';
    }

    /**
     * Admitad espera application/x-www-form-urlencoded con grant_type, client_id y scope.
     */
    protected function defaultBody(): array
    {
        return [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.admitad.client_id'),
            'scope' => config('services.admitad.scope', 'advcampaigns banners websites private_data'),
        ];
    }

    /**
     * La autenticación es HTTP Basic con client_id:client_secret (base64).
     * Se configura en el Connector con BasicAuthenticator.
     */
    public function defaultHeaders(): array
    {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
        ];
    }
}
