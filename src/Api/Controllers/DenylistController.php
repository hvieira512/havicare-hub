<?php

declare(strict_types=1);

namespace Hub\Api\Controllers;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Api\Services\DenylistService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class DenylistController
{
    public function __construct(
        private DenylistService $service,
        private JsonResponder $json,
    ) {
    }

    public function list(ServerRequestInterface $request): Response
    {
        return $this->json->result($this->service->list());
    }

    public function block(ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);
        if ($payload === null) {
            return $this->json->result(ApiError::invalidJson()->toArray());
        }

        $auth = RequestContext::auth($request);

        return $this->json->result($this->service->block($payload, $auth?->username ?? ''));
    }

    public function unblock(array $params): Response
    {
        return $this->json->result($this->service->unblock((string)($params['identity'] ?? '')));
    }
}
