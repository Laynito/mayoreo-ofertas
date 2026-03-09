<?php

namespace App\Http\Integrations\MercadoLibre\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Contracts\Body\HasBody;
use Saloon\Traits\Body\HasFormBody;
use Saloon\Traits\Plugins\AcceptsJson;

class GetTokenRequest extends Request implements HasBody
{
    use HasFormBody;
    use AcceptsJson;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $grantType,
        protected ?string $refreshToken = null
    ) {}

    public function resolveEndpoint(): string
    {
        return '/oauth/token';
    }

    /**
     * @return array{grant_type: string, client_id: string, client_secret: string, refresh_token?: string}
     */
    protected function defaultBody(): array
    {
        $body = [
            'grant_type' => $this->grantType,
            'client_id' => config('services.mercadolibre.app_id'),
            'client_secret' => config('services.mercadolibre.client_secret'),
        ];
        if ($this->refreshToken !== null) {
            $body['refresh_token'] = $this->refreshToken;
        }
        return $body;
    }
}
