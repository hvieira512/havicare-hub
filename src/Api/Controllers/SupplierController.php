<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\ErrorStatusMapper;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Api\Services\SupplierService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class SupplierController
{
    public function __construct(
        private SupplierService $service,
        private JsonResponder $json,
        private ErrorStatusMapper $status,
    ) {
    }

    public function list(ServerRequestInterface $request): Response
    {
        return $this->json->respond($this->service->list((string)$request->getUri()->getQuery()));
    }

    public function create(ServerRequestInterface $request): Response
    {
        $result = $this->service->create(RequestContext::requestBody($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    public function update(array $params, ServerRequestInterface $request): Response
    {
        $result = $this->service->update((int)$params['id'], RequestContext::requestBody($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    public function delete(array $params): Response
    {
        $result = $this->service->delete((int)$params['id']);

        return $this->json->respond($result, $this->status->map($result));
    }
}
