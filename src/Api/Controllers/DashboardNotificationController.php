<?php

declare(strict_types=1);

namespace Hub\Api\Controllers;

use Hub\Api\Http\ErrorStatusMapper;
use Hub\Api\Http\JsonResponder;
use Hub\Api\Http\RequestContext;
use Hub\Api\Services\DashboardNotificationService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final class DashboardNotificationController
{
    public function __construct(
        private DashboardNotificationService $service,
        private JsonResponder $json,
        private ErrorStatusMapper $status,
    ) {
    }

    public function list(ServerRequestInterface $request): Response
    {
        return $this->json->respond($this->service->list((string)$request->getUri()->getQuery()));
    }

    /**
     * O corpo ilegível segue como array vazio em vez de erro próprio: este endpoint sempre
     * respondeu "ids array is required" a um corpo que não é JSON, e é o que o serviço diz.
     */
    public function markRead(ServerRequestInterface $request): Response
    {
        $result = $this->service->markRead(RequestContext::jsonBody($request) ?? []);

        return $this->json->respond($result, $this->status->map($result));
    }

    public function delete(array $params): Response
    {
        $result = $this->service->delete((int)$params['id']);

        return $this->json->respond($result, $this->status->map($result));
    }
}
