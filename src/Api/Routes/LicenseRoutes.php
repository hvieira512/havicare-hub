<?php

declare(strict_types=1);

use Hub\Api\Http\RequestContext;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\LicenseService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    LicenseService $licenses,
): array {
    return [
        new ApiRoute('GET', '/api/licenses', static fn(array $params, ServerRequestInterface $request): array
            => $licenses->list((string)$request->getUri()->getQuery())),
        new ApiRoute(
            'POST',
            '/api/licenses',
            static fn(array $params, ServerRequestInterface $request): array
                => $licenses->create(RequestContext::body($request)),
            body: ApiRoute::JSON_BODY,
            status: 201,
        ),
        new ApiRoute(
            'PUT',
            '/api/licenses/{id:\d+}',
            static fn(array $params, ServerRequestInterface $request): array
                => $licenses->update((int)$params['id'], RequestContext::body($request)),
            body: ApiRoute::JSON_BODY,
        ),
        new ApiRoute('DELETE', '/api/licenses/{id:\d+}', static fn(array $params): array
            => $licenses->delete((int)$params['id'])),
    ];
};
