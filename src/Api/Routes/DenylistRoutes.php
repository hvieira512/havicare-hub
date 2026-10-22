<?php

declare(strict_types=1);

use Hub\Api\Http\RequestContext;
use Hub\Api\Request\DenylistBlockRequest;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\DenylistService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    DenylistService $denylist,
): array {
    return [
        new ApiRoute('GET', '/api/denylist', static fn(array $params, ServerRequestInterface $request): array
            => $denylist->list((string)$request->getUri()->getQuery())),
        new ApiRoute(
            'POST',
            '/api/denylist',
            static fn(array $params, ServerRequestInterface $request, DenylistBlockRequest $input): array => $denylist->block(
                $input,
                RequestContext::auth($request)?->username ?? '',
            ),
            body: ApiRoute::JSON_BODY,
            request: DenylistBlockRequest::class,
            coerceStrings: true,
        ),
        // `{identity}` é uma string (IMEI, MAC ou uid), não `\d+`.
        new ApiRoute('DELETE', '/api/denylist/{identity}', static fn(array $params): array
            => $denylist->unblock((string)($params['identity'] ?? ''))),
    ];
};
