<?php

use Hub\Api\Controllers\DashboardNotificationController;
use Hub\Api\Routing\ApiRoute;

return static function (DashboardNotificationController $notifications): array {
    return [
        new ApiRoute('GET', '/api/notifications', [$notifications, 'list']),
        new ApiRoute('PATCH', '/api/notifications/read', [$notifications, 'markRead']),
        new ApiRoute('DELETE', '/api/notifications/{id:\d+}', [$notifications, 'delete']),
    ];
};
