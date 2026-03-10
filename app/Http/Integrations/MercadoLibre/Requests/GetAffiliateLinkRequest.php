<?php

namespace App\Http\Integrations\MercadoLibre\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request as BaseRequest;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\FatalRequestException;
use App\Http\Integrations\MercadoLibre\MercadoLibreConnector;

class GetAffiliateLinkRequest extends BaseRequest
{
    use AcceptsJson;

    protected Method $method = Method::GET;

    /** Reintentar si falla (p. ej. 401 token expirado). */
    public ?int $tries = 2;

    public function __construct(
        protected string $urlProducto
    ) {}

    public function resolveEndpoint(): string
    {
        $userId = config('services.mercadolibre.user_id');
        return '/users/' . $userId . '/links';
    }

    protected function defaultQuery(): array
    {
        return [
            'url' => $this->urlProducto,
        ];
    }

    /**
     * Si recibimos 401, limpiar token para que el reintento obtenga uno nuevo.
     */
    public function handleRetry(FatalRequestException|RequestException $exception, BaseRequest $request): bool
    {
        if ($exception->getResponse()?->status() === 401) {
            MercadoLibreConnector::clearTokenCache();
            return true;
        }
        return false;
    }
}
