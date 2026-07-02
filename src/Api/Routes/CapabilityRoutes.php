<?php

use Hub\Api\Controllers\CapabilityController;
use Hub\Api\Routing\ApiRoute;

return static function (
    CapabilityController $capabilities,
): array {
    return [
        new ApiRoute('GET', '/api/capabilities', [$capabilities, 'list']),
        new ApiRoute('GET', '/api/capabilities/{id:\d+}', [$capabilities, 'show']),
    ];
};
