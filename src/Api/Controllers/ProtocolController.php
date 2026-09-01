<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\JsonResponder;
use Hub\Api\Services\ProtocolService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class ProtocolController
{
    public function __construct(
        private ProtocolService $service,
        private JsonResponder $json,
    ) {
    }

    public function list(ServerRequestInterface $request): Response
    {
        return $this->json->result($this->service->list());
    }

    public function configCatalog(array $params): Response
    {
        return $this->json->result($this->service->configCatalog($params));
    }
}
