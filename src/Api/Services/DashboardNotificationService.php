<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Repository\ApiDataAccess;

final class DashboardNotificationService
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(private ApiDataAccess $db)
    {
    }

    public function list(string $query = ''): array
    {
        parse_str($query, $params);
        $limit = max(1, min(self::MAX_LIMIT, (int)($params['limit'] ?? self::DEFAULT_LIMIT)));

        return [
            'data' => $this->db->dashboardNotifications->latest($limit),
            'unreadCount' => $this->db->dashboardNotifications->unreadCount(),
        ];
    }

    public function markRead(string $body): array
    {
        $payload = json_decode($body, true);
        if (!is_array($payload) || !isset($payload['ids']) || !is_array($payload['ids'])) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'ids array is required']];
        }

        $ids = [];
        foreach ($payload['ids'] as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                return ['error' => ['code' => 'invalid_request', 'message' => 'ids must contain positive integers']];
            }
            $normalized = (int)$id;
            if ($normalized <= 0) {
                return ['error' => ['code' => 'invalid_request', 'message' => 'ids must contain positive integers']];
            }
            $ids[] = $normalized;
        }

        $this->db->dashboardNotifications->markRead($ids);

        return [
            'status' => 'ok',
            'unreadCount' => $this->db->dashboardNotifications->unreadCount(),
        ];
    }

    public function delete(int $id): array
    {
        if ($id <= 0) {
            return ['error' => ['code' => 'invalid_request', 'message' => 'notification id must be a positive integer']];
        }
        if (!$this->db->dashboardNotifications->delete($id)) {
            return ['error' => ['code' => 'notification_not_found', 'message' => 'Notification not found']];
        }

        return [
            'status' => 'ok',
            'unreadCount' => $this->db->dashboardNotifications->unreadCount(),
        ];
    }
}
