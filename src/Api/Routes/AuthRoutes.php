<?php

use Hub\Api\Controllers\AuthController;
use Hub\Api\Routing\ApiRoute;

return static function (
    AuthController $auth
): array {
    return [
        new ApiRoute('POST', '/api/auth/login', [$auth, 'login']),
        new ApiRoute('POST', '/api/auth/license-token', [$auth, 'licenseToken']),
    ];
};
