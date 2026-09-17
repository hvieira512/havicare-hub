<?php

/**
 * A planta de um radar. O `sync` é o botão e mais nada: a ida à cloud do fabricante acontece
 * quando alguém a pede, e nunca por conta própria.
 */

use Hub\Api\Controllers\RadarLayoutController;
use Hub\Api\Routing\ApiRoute;

return static function (
    RadarLayoutController $radarLayouts,
): array {
    return [
        new ApiRoute('GET', '/api/devices/{imei}/radar-layout', [$radarLayouts, 'show']),
        new ApiRoute('POST', '/api/devices/{imei}/radar-layout/sync', [$radarLayouts, 'sync']),
    ];
};
