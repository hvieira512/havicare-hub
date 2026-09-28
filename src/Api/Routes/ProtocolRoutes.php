<?php

use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\ProtocolService;

return static function (
    ProtocolService $protocols,
): array {
    return [
        new ApiRoute('GET', '/api/protocols', static fn(): array => $protocols->list()),
        new ApiRoute('GET', '/api/protocols/{protocol}/config-catalog', static fn(array $params): array
            => $protocols->configCatalog($params)),
    ];
};
