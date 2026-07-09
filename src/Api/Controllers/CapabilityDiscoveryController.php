<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\ErrorStatusMapper;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Api\Services\CapabilityDiscoveryService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class CapabilityDiscoveryController
{
    public function __construct(
        private CapabilityDiscoveryService $service,
        private JsonResponder $json,
        private ErrorStatusMapper $status,
    ) {
    }

    public function list(): Response
    {
        return $this->json->respond($this->service->list());
    }

    public function show(array $params): Response
    {
        $result = $this->service->show((string)$params['id']);

        return $this->json->respond($result, $this->status->map($result));
    }

    public function preview(ServerRequestInterface $request): Response
    {
        $result = $this->service->preview(
            RequestContext::requestBody($request),
            RequestContext::auth($request),
            RequestContext::baseUrl($request),
        );

        return $this->json->respond($result, $this->status->map($result));
    }

    public function apply(array $params): Response
    {
        $result = $this->service->apply((string)$params['id']);

        return $this->json->respond($result, $this->status->map($result));
    }
}
