<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Api\Services\CompanyService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class CompanyController
{
    public function __construct(
        private CompanyService $service,
        private JsonResponder $json,
    ) {
    }

    public function list(ServerRequestInterface $request): Response
    {
        return $this->json->result($this->service->list((string)$request->getUri()->getQuery()));
    }

    public function create(ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);

        return $this->json->result($payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->create($payload));
    }

    public function update(array $params, ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);

        return $this->json->result($payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->update((int)$params['id'], $payload));
    }

    public function delete(array $params): Response
    {
        return $this->json->result($this->service->delete((int)$params['id']));
    }
}
