<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\ErrorStatusMapper;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Services\CapabilityService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class CapabilityController
{
    public function __construct(
        private CapabilityService $service,
        private JsonResponder $json,
        private ErrorStatusMapper $status,
    ) {
    }

    public function list(ServerRequestInterface $request): Response
    {
        return $this->json->respond($this->service->list((string)$request->getUri()->getQuery()));
    }

    public function show(array $params): Response
    {
        $result = $this->service->show((int)$params['id']);

        return $this->json->respond($result, $this->status->map($result));
    }
}
