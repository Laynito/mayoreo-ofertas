<?php

namespace App\Http\Integrations\Admitad\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Contracts\Body\HasBody;
use Saloon\Traits\Body\HasFormBody;
use Saloon\Traits\Plugins\AcceptsJson;

/**
 * Acorta un enlace de Admitad (debe ser de dominio admitad).
 * POST https://api.admitad.com/shortlink/modify/ con link=...
 * Requiere scope short_link.
 */
class PostShortlinkRequest extends Request implements HasBody
{
    use HasFormBody;
    use AcceptsJson;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $link
    ) {}

    public function resolveEndpoint(): string
    {
        return '/shortlink/modify/';
    }

    protected function defaultBody(): array
    {
        return [
            'link' => $this->link,
        ];
    }
}
