<?php

use Hub\Api\Controllers\DeviceController;
use Hub\Api\Routing\ApiRoute;

return static function (
    DeviceController $devices,
): array {
    return [
        new ApiRoute('GET', '/api/devices', [$devices, 'list']),
        new ApiRoute('GET', '/api/devices/{imei}', [$devices, 'show']),
        new ApiRoute('GET', '/api/devices/{imei}/stream', [$devices, 'stream']),
        new ApiRoute('GET', '/api/devices/{imei}/links', [$devices, 'links']),
        new ApiRoute('POST', '/api/devices/{imei}/links/{linkedImei}', [$devices, 'createLink']),
        new ApiRoute('DELETE', '/api/devices/{imei}/links/{linkedImei}', [$devices, 'deleteLink']),
        new ApiRoute('GET', '/api/devices/{imei}/diaper-sensitivity', [$devices, 'diaperSensitivity']),
        new ApiRoute('PUT', '/api/devices/{imei}/diaper-sensitivity', [$devices, 'updateDiaperSensitivity']),
        new ApiRoute('DELETE', '/api/devices/{imei}/diaper-sensitivity', [$devices, 'deleteDiaperSensitivity']),
        new ApiRoute('POST', '/api/devices/{imei}/requests', [$devices, 'requestFeature']),
        new ApiRoute('PATCH', '/api/devices/{imei}/association', [$devices, 'patchAssociation']),
        new ApiRoute('DELETE', '/api/devices/{imei}/association', [$devices, 'deleteAssociation']),
        new ApiRoute('GET', '/api/commands/{id}', [$devices, 'commandStatus']),
        new ApiRoute('POST', '/api/devices', [$devices, 'create']),
        new ApiRoute('PUT', '/api/devices/{imei}', [$devices, 'update']),
        new ApiRoute('PATCH', '/api/devices/{imei}/configurations', [$devices, 'updateConfigurations']),
        new ApiRoute('DELETE', '/api/devices/{imei}', [$devices, 'delete']),
    ];
};
