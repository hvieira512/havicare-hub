<?php

declare(strict_types=1);

use Hub\Api\Request\CompanyWriteRequest;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\CompanyService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    CompanyService $company,
): array {
    return [
        new ApiRoute('GET', '/api/companies', static fn(array $params, ServerRequestInterface $request): array
            => $company->list((string)$request->getUri()->getQuery()), revalidated: true),
        new ApiRoute(
            'POST',
            '/api/companies',
            static fn(array $params, ServerRequestInterface $request, CompanyWriteRequest $input): array
                => $company->create($input),
            body: ApiRoute::JSON_BODY,
            status: 201,
            request: CompanyWriteRequest::class,
        ),
        new ApiRoute(
            'PUT',
            '/api/companies/{id:\d+}',
            static fn(array $params, ServerRequestInterface $request, CompanyWriteRequest $input): array
                => $company->update((int)$params['id'], $input),
            body: ApiRoute::JSON_BODY,
            request: CompanyWriteRequest::class,
        ),
        new ApiRoute('DELETE', '/api/companies/{id:\d+}', static fn(array $params): array
            => $company->delete((int)$params['id'])),
    ];
};
