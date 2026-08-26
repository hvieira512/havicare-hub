<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\JsonResponder;
use Hub\Api\Services\SupplierService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class SupplierController
{
    public function __construct(
        private SupplierService $service,
        private JsonResponder $json,
    ) {
    }

    public function list(ServerRequestInterface $request): Response
    {
        return $this->json->respond($this->service->list((string)$request->getUri()->getQuery()));
    }
}
