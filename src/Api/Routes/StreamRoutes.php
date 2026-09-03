<?php

use Hub\Api\Controllers\StreamController;
use Hub\Api\Routing\ApiRoute;

return static function (
    StreamController $stream
): array {
    return [
        new ApiRoute('GET', '/api/stream', [$stream, 'stream']),
    ];
};
