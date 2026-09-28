<?php

use Hub\Api\Http\RequestContext;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\CompanyService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    CompanyService $company,
): array {
    return [
        new ApiRoute('GET', '/api/companies', static fn(array $params, ServerRequestInterface $request): array
            => $company->list((string)$request->getUri()->getQuery())),
        new ApiRoute(
            'POST',
            '/api/companies',
            static fn(array $params, ServerRequestInterface $request): array
                => $company->create(RequestContext::body($request)),
            body: ApiRoute::JSON_BODY,
        ),
        new ApiRoute(
            'PUT',
            '/api/companies/{id:\d+}',
            static fn(array $params, ServerRequestInterface $request): array
                => $company->update((int)$params['id'], RequestContext::body($request)),
            body: ApiRoute::JSON_BODY,
        ),
        new ApiRoute('DELETE', '/api/companies/{id:\d+}', static fn(array $params): array
            => $company->delete((int)$params['id'])),
    ];
};
