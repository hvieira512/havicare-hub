<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Request\NotificationReadRequest;
use Hub\Api\Request\RequestBinder;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;

final class DashboardNotificationService
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(private ApiDataAccess $db)
    {
    }

    /** @return array<string, mixed> */
    public function list(string $query = ''): array
    {
        parse_str($query, $params);
        $limit = max(1, min(self::MAX_LIMIT, (int)($params['limit'] ?? self::DEFAULT_LIMIT)));

        return [
            'data' => $this->db->dashboardNotifications->latest($limit),
            'unreadCount' => $this->db->dashboardNotifications->unreadCount(),
        ];
    }

    /**
     * Um corpo que não é JSON chega aqui como array vazio, e não à parte: este endpoint
     * sempre respondeu "ids array is required" a um corpo ilegível, e é o que os clientes
     * esperam ver.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function markRead(array $payload): array
    {
        $request = (new RequestBinder())->bind($payload, NotificationReadRequest::class, coerceStrings: true);
        if (is_array($request)) {
            return $request;
        }

        $ids = [];
        foreach ($request->ids ?? [] as $id) {
            $normalized = (int)$id;
            $ids[] = $normalized;
        }

        $this->db->dashboardNotifications->markRead($ids);

        return [
            'status' => 'ok',
            'unreadCount' => $this->db->dashboardNotifications->unreadCount(),
        ];
    }

    /** @return array<string, mixed> */
    public function delete(int $id): array
    {
        if ($id <= 0) {
            return ApiError::invalidRequest('notification id must be a positive integer')->toArray();
        }
        if (!$this->db->dashboardNotifications->delete($id)) {
            return ApiError::notificationNotFound()->toArray();
        }

        return [
            'status' => 'ok',
            'unreadCount' => $this->db->dashboardNotifications->unreadCount(),
        ];
    }
}
