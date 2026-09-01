<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\JsonResponder;
use Hub\Api\Services\CapabilityService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class CapabilityController
{
    public function __construct(
        private CapabilityService $service,
        private JsonResponder $json,
    ) {
    }

    public function list(ServerRequestInterface $request): Response
    {
        return $this->json->result($this->service->list((string)$request->getUri()->getQuery()));
    }

    public function show(array $params): Response
    {
        return $this->json->result($this->service->show((int)$params['id']));
    }
}
