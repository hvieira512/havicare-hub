<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\ErrorStatusMapper;
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
        private ErrorStatusMapper $status,
    ) {
    }

    /**
     * O estado era 401 para qualquer erro, e por isso um corpo sem password -- ou que nem
     * sequer era JSON -- saía como "credencial recusada" em vez de "pedido mal formado". A
     * credencial errada continua a responder 401, que é o que o `invalid_credentials` e o
     * `invalid_refresh_token` declaram; o resto passa a responder o que o seu código diz.
     */
    public function login(ServerRequestInterface $request): Response
    {
        $payload = RequestContext::jsonBody($request);
        $result = $payload === null
            ? ApiError::invalidJson()->toArray()
            : $this->service->login($payload, RequestContext::requestId($request));

        return $this->json->respond($result, $this->status->map($result));
    }

    /** O único erro daqui é o `forbidden`, e o 403 passa a vir dele em vez de escrito à mão. */
    public function streamTicket(ServerRequestInterface $request): Response
    {
        $result = $this->service->streamTicket(RequestContext::auth($request));

        return $this->json->respond($result, $this->status->map($result));
    }
}
