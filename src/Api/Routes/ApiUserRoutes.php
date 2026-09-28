<?php

use Hub\Api\Http\RequestContext;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\ApiUserService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    ApiUserService $apiUsers,
): array {
    return [
        new ApiRoute('GET', '/api/users', static fn(array $params, ServerRequestInterface $request): array
            => $apiUsers->list((string)$request->getUri()->getQuery())),
        new ApiRoute(
            'POST',
            '/api/users',
            static fn(array $params, ServerRequestInterface $request): array
                => $apiUsers->create(RequestContext::body($request)),
            body: ApiRoute::JSON_BODY,
            status: 201,
        ),
        new ApiRoute(
            'PUT',
            '/api/users/{id:\d+}',
            static fn(array $params, ServerRequestInterface $request): array
                => $apiUsers->update((int)$params['id'], RequestContext::body($request)),
            body: ApiRoute::JSON_BODY,
        ),
        new ApiRoute('DELETE', '/api/users/{id:\d+}', static fn(array $params): array
            => $apiUsers->delete((int)$params['id'])),
    ];
};
