<?php

namespace Hub\Api\OpenApi\Schemas;

use Hub\Api\OpenApi\Responses;

/**
 * Dashboard notification feed.
 */
final class NotificationSchemas
{
    public static function schemas(): array
    {
        return [
            'DashboardNotificationItem' => [
                'type' => 'object',
                'required' => ['id', 'type', 'imei', 'protocol', 'occurrenceCount', 'firstSeenAt', 'lastSeenAt'],
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'type' => ['type' => 'string', 'example' => 'device_not_authorized'],
                    'imei' => ['type' => 'string', 'example' => '861265062544868'],
                    'protocol' => ['type' => 'string', 'example' => 'vivistar-iw'],
                    'model' => ['type' => 'string', 'example' => 'VL16P'],
                    'ident' => ['type' => 'string', 'example' => ''],
                    'reason' => ['type' => 'string', 'example' => 'device_not_authorized'],
                    // O dono, quando o protocolo o diz. Vêm os dois ou nenhum.
                    'licenseId' => ['type' => 'integer', 'example' => 1001],
                    'company' => ['type' => ['string', 'null'], 'example' => 'hitcare'],
                    'occurrenceCount' => ['type' => 'integer', 'example' => 2],
                    'firstSeenAt' => ['type' => 'string', 'format' => 'date-time'],
                    'lastSeenAt' => ['type' => 'string', 'format' => 'date-time'],
                    'readAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                ],
            ],
            'DashboardNotificationListResponse' => [
                'type' => 'object',
                'required' => ['data', 'unreadCount'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => Responses::ref('DashboardNotificationItem')],
                    'unreadCount' => ['type' => 'integer', 'example' => 1],
                ],
            ],
            'DashboardNotificationReadRequest' => [
                'type' => 'object',
                'required' => ['ids'],
                'properties' => [
                    'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'example' => [1, 2]],
                ],
            ],
            'DashboardNotificationReadResponse' => [
                'type' => 'object',
                'required' => ['status', 'unreadCount'],
                'properties' => [
                    'status' => ['type' => 'string', 'example' => 'ok'],
                    'unreadCount' => ['type' => 'integer', 'example' => 0],
                ],
            ],
        ];
    }
}
