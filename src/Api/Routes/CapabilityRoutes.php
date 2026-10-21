<?php

declare(strict_types=1);

use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\CapabilityService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    CapabilityService $capabilities,
): array {
    return [
        new ApiRoute('GET', '/api/capabilities', static fn(array $params, ServerRequestInterface $request): array
            => $capabilities->list((string)$request->getUri()->getQuery())),
    ];
};
