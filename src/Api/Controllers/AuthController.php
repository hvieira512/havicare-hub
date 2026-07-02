<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Api\Services\AuthService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class AuthController
{
    public function __construct(
        private AuthService $service,
        private JsonResponder $json,
    ) {
    }

    public function login(ServerRequestInterface $request): Response
    {
        $result = $this->service->login(RequestContext::requestBody($request), RequestContext::requestId($request));

        return $this->json->respond($result, isset($result['error']) ? 401 : 200);
    }
}
