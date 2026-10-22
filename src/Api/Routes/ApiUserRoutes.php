<?php

declare(strict_types=1);

use Hub\Api\Request\ApiUserWriteRequest;
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
            static fn(array $params, ServerRequestInterface $request, ApiUserWriteRequest $input): array
                => $apiUsers->create($input),
            body: ApiRoute::JSON_BODY,
            status: 201,
            request: ApiUserWriteRequest::class,
            groups: [ApiUserWriteRequest::GROUP_CREATE],
            codeByField: ApiUserWriteRequest::ERROR_CODE_BY_FIELD,
        ),
        new ApiRoute(
            'PUT',
            '/api/users/{id:\d+}',
            static fn(array $params, ServerRequestInterface $request, ApiUserWriteRequest $input): array
                => $apiUsers->update((int)$params['id'], $input),
            body: ApiRoute::JSON_BODY,
            request: ApiUserWriteRequest::class,
            codeByField: ApiUserWriteRequest::ERROR_CODE_BY_FIELD,
        ),
        new ApiRoute('DELETE', '/api/users/{id:\d+}', static fn(array $params): array
            => $apiUsers->delete((int)$params['id'])),
    ];
};
