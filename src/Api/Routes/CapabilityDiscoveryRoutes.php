<?php

use Hub\Api\Controllers\CapabilityDiscoveryController;
use Hub\Api\Routing\ApiRoute;

return static function (CapabilityDiscoveryController $discovery): array {
    return [
        new ApiRoute('GET', '/api/capability-discovery', [$discovery, 'list']),
        new ApiRoute('POST', '/api/capability-discovery', [$discovery, 'preview']),
        new ApiRoute('GET', '/api/capability-discovery/{id}', [$discovery, 'show']),
        new ApiRoute('POST', '/api/capability-discovery/{id}/apply', [$discovery, 'apply']),
    ];
};
