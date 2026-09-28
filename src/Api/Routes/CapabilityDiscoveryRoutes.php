<?php

use Hub\Api\Http\RequestContext;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\CapabilityDiscoveryService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    CapabilityDiscoveryService $discovery,
): array {
    return [
        new ApiRoute('GET', '/api/capability-discovery', static fn(): array => $discovery->list()),
        new ApiRoute(
            'POST',
            '/api/capability-discovery',
            static fn(array $params, ServerRequestInterface $request): array => $discovery->preview(
                RequestContext::body($request),
                RequestContext::auth($request),
                RequestContext::baseUrl($request),
            ),
            body: ApiRoute::JSON_BODY,
        ),
        new ApiRoute('GET', '/api/capability-discovery/{id}', static fn(array $params): array
            => $discovery->show((string)$params['id'])),
        new ApiRoute('POST', '/api/capability-discovery/{id}/apply', static fn(array $params): array
            => $discovery->apply((string)$params['id'])),
    ];
};
