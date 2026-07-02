<?php

use Hub\Api\Controllers\ProtocolController;
use Hub\Api\Routing\ApiRoute;

return static function (
    ProtocolController $protocols,
): array {
    return [
        new ApiRoute('GET', '/api/protocols/{protocol}/config-catalog', [$protocols, 'configCatalog']),
    ];
};
