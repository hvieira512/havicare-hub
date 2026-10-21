<?php

declare(strict_types=1);

namespace Hub\Api\OpenApi\Schemas;

use Hub\Api\OpenApi\Responses;

/** As notificações da dashboard. */
final class NotificationSchemas
{
    /** @return array<string, mixed> */
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
                    'reason' => ['oneOf' => [
                        ['type' => 'string', 'example' => 'device_not_authorized'],
                        [
                            'type' => 'object',
                            'description' => 'hub_unclean_restart: the run that ended abruptly.',
                            'properties' => [
                                'pid' => ['type' => 'integer', 'nullable' => true],
                                'startedAt' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                            ],
                        ],
                    ]],
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
            'DenylistEntry' => [
                'type' => 'object',
                'required' => ['identity', 'protocol', 'createdBy', 'createdAt'],
                'properties' => [
                    'identity' => ['type' => 'string', 'example' => '357000000000123'],
                    'protocol' => ['type' => 'string', 'example' => 'four-p-touch'],
                    'note' => ['type' => ['string', 'null']],
                    'createdBy' => ['type' => 'string', 'example' => 'admin'],
                    'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'DenylistListResponse' => CommonSchemas::collection('DenylistEntry', withColumns: true),
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
