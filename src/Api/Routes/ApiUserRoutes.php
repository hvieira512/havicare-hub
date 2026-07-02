<?php

use Hub\Api\Controllers\ApiUserController;
use Hub\Api\Routing\ApiRoute;

return static function (
    ApiUserController $apiUsers,
): array {
    return [
        new ApiRoute('GET', '/api/users', [$apiUsers, 'list']),
        new ApiRoute('POST', '/api/users', [$apiUsers, 'create']),
        new ApiRoute('PUT', '/api/users/{id:\d+}', [$apiUsers, 'update']),
        new ApiRoute('DELETE', '/api/users/{id:\d+}', [$apiUsers, 'delete']),
    ];
};
