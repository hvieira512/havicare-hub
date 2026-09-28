<?php

use Hub\Api\Http\RequestContext;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\DashboardNotificationService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    DashboardNotificationService $notifications,
): array {
    return [
        new ApiRoute('GET', '/api/notifications', static fn(array $params, ServerRequestInterface $request): array
            => $notifications->list((string)$request->getUri()->getQuery())),
        // Não declara corpo: um corpo ilegível segue como array vazio em vez de erro próprio,
        // porque esta rota sempre respondeu "ids array is required" a um corpo que não é JSON.
        new ApiRoute('PATCH', '/api/notifications/read', static fn(array $params, ServerRequestInterface $request): array
            => $notifications->markRead(RequestContext::jsonBody($request) ?? [])),
        new ApiRoute('DELETE', '/api/notifications/{id:\d+}', static fn(array $params): array
            => $notifications->delete((int)$params['id'])),
    ];
};
