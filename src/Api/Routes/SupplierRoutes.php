<?php

use Hub\Api\Controllers\SupplierController;
use Hub\Api\Routing\ApiRoute;

return static function (
    SupplierController $suppliers,
): array {
    return [
        // Suppliers are defined in code, so this collection is read-only.
        new ApiRoute('GET', '/api/suppliers', [$suppliers, 'list']),
    ];
};
