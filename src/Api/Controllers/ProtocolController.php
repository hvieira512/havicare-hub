<?php

namespace Hub\Api\Controllers;

use Hub\Api\Http\ErrorStatusMapper;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Services\ProtocolService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class ProtocolController
{
    public function __construct(
        private ProtocolService $service,
        private JsonResponder $json,
        private ErrorStatusMapper $status,
    ) {
    }

    public function list(ServerRequestInterface $request): Response
    {
        return $this->json->respond($this->service->list());
    }

    public function configCatalog(array $params): Response
    {
        $result = $this->service->configCatalog($params);

        return $this->json->respond($result, $this->status->map($result));
    }
}
