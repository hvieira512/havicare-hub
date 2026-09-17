<?php

/**
 * Pendem da licença porque é dela que são: o acesso à cloud do fabricante dos radares não é
 * comum à frota, e nem o endereço base coincide entre inquilinos.
 */

use Hub\Api\Controllers\RadarCredentialsController;
use Hub\Api\Routing\ApiRoute;

return static function (
    RadarCredentialsController $radarCredentials,
): array {
    return [
        new ApiRoute('GET', '/api/licenses/{id:\d+}/radar-credentials', [$radarCredentials, 'show']),
        new ApiRoute('PUT', '/api/licenses/{id:\d+}/radar-credentials', [$radarCredentials, 'save']),
        new ApiRoute('DELETE', '/api/licenses/{id:\d+}/radar-credentials', [$radarCredentials, 'delete']),
    ];
};
