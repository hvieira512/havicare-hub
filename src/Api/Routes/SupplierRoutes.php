<?php

use Hub\Api\Controllers\SupplierController;
use Hub\Api\Routing\ApiRoute;

return static function (
    SupplierController $suppliers,
): array {
    return [
        // Suppliers are defined in code; the only supported write is the
        // enabled toggle, so there is no create or delete route.
        new ApiRoute('GET', '/api/suppliers', [$suppliers, 'list']),
        new ApiRoute('PUT', '/api/suppliers/{id:\d+}', [$suppliers, 'update']),
    ];
};
