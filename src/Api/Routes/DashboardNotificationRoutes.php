<?php

declare(strict_types=1);

use Hub\Api\Request\NotificationReadRequest;
use Hub\Api\Routing\ApiRoute;
use Hub\Api\Services\DashboardNotificationService;
use Psr\Http\Message\ServerRequestInterface;

return static function (
    DashboardNotificationService $notifications,
): array {
    return [
        new ApiRoute('GET', '/api/notifications', static fn(array $params, ServerRequestInterface $request): array
            => $notifications->list((string)$request->getUri()->getQuery())),
        // Não declara corpo: um corpo ilegível segue como array vazio e responde "ids array is required".
        new ApiRoute(
            'PATCH',
            '/api/notifications/read',
            static fn(array $params, ServerRequestInterface $request, NotificationReadRequest $input): array
                => $notifications->markRead($input),
            request: NotificationReadRequest::class,
            coerceStrings: true,
        ),
        new ApiRoute('DELETE', '/api/notifications/{id:\d+}', static fn(array $params): array
            => $notifications->delete((int)$params['id'])),
    ];
};
