<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Api\Services\RadarCredentialsService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class RadarCredentialsController
{
    public function __construct(
        private RadarCredentialsService $service,
        private JsonResponder $json,
    ) {
    }

    public function show(array $params): Response
    {
        return $this->json->result($this->service->show((int)$params['id']));
    }

    public function save(array $params, ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);

        return $this->json->result($payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->save((int)$params['id'], $payload));
    }

    public function delete(array $params): Response
    {
        return $this->json->result($this->service->delete((int)$params['id']));
    }
}
