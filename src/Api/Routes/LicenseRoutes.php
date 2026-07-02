<?php

use Hub\Api\Controllers\LicenseController;
use Hub\Api\Routing\ApiRoute;

return static function (
    LicenseController $licenses,
): array {
    return [
        new ApiRoute('GET', '/api/licenses', [$licenses, 'list']),
        new ApiRoute('POST', '/api/licenses', [$licenses, 'create']),
        new ApiRoute('PUT', '/api/licenses/{id:\d+}', [$licenses, 'update']),
        new ApiRoute('DELETE', '/api/licenses/{id:\d+}', [$licenses, 'delete']),
    ];
};
