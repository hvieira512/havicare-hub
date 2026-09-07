<?php

use Hub\Api\Controllers\DenylistController;
use Hub\Api\Routing\ApiRoute;

return static function (DenylistController $denylist): array {
    return [
        new ApiRoute('GET', '/api/denylist', [$denylist, 'list']),
        new ApiRoute('POST', '/api/denylist', [$denylist, 'block']),
        // `{identity}` é uma string (IMEI, MAC ou uid), não `\d+`.
        new ApiRoute('DELETE', '/api/denylist/{identity}', [$denylist, 'unblock']),
    ];
};
