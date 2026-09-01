<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\ApiError;
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
    ) {
    }

    public function list(): Response
    {
        return $this->json->result($this->service->list());
    }

    public function show(array $params): Response
    {
        return $this->json->result($this->service->show((string)$params['id']));
    }

    public function preview(ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);

        return $this->json->result($payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->preview(
                $payload,
                RequestContext::auth($request),
                RequestContext::baseUrl($request),
            ));
    }

    public function apply(array $params): Response
    {
        return $this->json->result($this->service->apply((string)$params['id']));
    }
}
