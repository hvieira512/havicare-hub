<?php

declare(strict_types=1);

use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\SupplierService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    SupplierService $suppliers,
): array {
    return [
        // Os fornecedores estão definidos em código, e por isso esta colecção é só de leitura.
        new ApiRoute('GET', '/api/suppliers', static fn(array $params, ServerRequestInterface $request): array
            => $suppliers->list((string)$request->getUri()->getQuery())),
    ];
};
