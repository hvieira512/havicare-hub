<?php

use Hub\Api\Controllers\SupplierController;
use Hub\Api\Routing\ApiRoute;

return static function (
    SupplierController $suppliers,
): array {
    return [
        new ApiRoute('GET', '/api/suppliers', [$suppliers, 'list']),
        new ApiRoute('POST', '/api/suppliers', [$suppliers, 'create']),
        new ApiRoute('PUT', '/api/suppliers/{id:\d+}', [$suppliers, 'update']),
        new ApiRoute('DELETE', '/api/suppliers/{id:\d+}', [$suppliers, 'delete']),
    ];
};
