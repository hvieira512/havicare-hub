<?php

use Hub\Api\Controllers\SupplierController;
use Hub\Api\Routing\ApiRoute;

return static function (
    SupplierController $suppliers,
): array {
    return [
        // Os fornecedores estão definidos em código, e por isso esta colecção é só de leitura.
        new ApiRoute('GET', '/api/suppliers', [$suppliers, 'list']),
    ];
};
