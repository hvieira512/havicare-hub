<?php

declare(strict_types=1);

use Hub\Api\Request\LicenseWriteRequest;
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
            static fn(array $params, ServerRequestInterface $request, LicenseWriteRequest $input): array
                => $licenses->create($input),
            body: ApiRoute::JSON_BODY,
            status: 201,
            request: LicenseWriteRequest::class,
            groups: [LicenseWriteRequest::GROUP_CREATE],
            // O `licenseId` chega como texto tantas vezes como inteiro.
            coerceStrings: true,
        ),
        new ApiRoute(
            'PUT',
            '/api/licenses/{id:\d+}',
            static fn(array $params, ServerRequestInterface $request, LicenseWriteRequest $input): array
                => $licenses->update((int)$params['id'], $input),
            body: ApiRoute::JSON_BODY,
            request: LicenseWriteRequest::class,
            coerceStrings: true,
        ),
        new ApiRoute('DELETE', '/api/licenses/{id:\d+}', static fn(array $params): array
            => $licenses->delete((int)$params['id'])),
    ];
};
