<?php

use Hub\Api\Controllers\ModelController;
use Hub\Api\Routing\ApiRoute;

return static function (
    ModelController $models,
): array {
    return [
        new ApiRoute('GET', '/api/models', [$models, 'list']),
        new ApiRoute('GET', '/api/device-types/suppliers', [$models, 'filters']),
        new ApiRoute('GET', '/api/device-types/suppliers/models', [$models, 'deviceTypeSuppliersModels']),
        new ApiRoute('GET', '/api/models/template', [$models, 'template']),
        new ApiRoute('GET', '/api/models/{id:\d+}', [$models, 'show']),
        new ApiRoute('POST', '/api/models', [$models, 'create']),
        new ApiRoute('PUT', '/api/models/{id:\d+}', [$models, 'update']),
        new ApiRoute('DELETE', '/api/models/{id:\d+}', [$models, 'delete']),
    ];
};
