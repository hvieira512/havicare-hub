<?php

use Hub\Api\Controllers\CompanyController;
use Hub\Api\Routing\ApiRoute;

return static function (
    CompanyController $company,
): array {
    return [
        new ApiRoute('GET', '/api/companies', [$company, 'list']),
        new ApiRoute('POST', '/api/companies', [$company, 'create']),
        new ApiRoute('PUT', '/api/companies/{id:\d+}', [$company, 'update']),
        new ApiRoute('DELETE', '/api/companies/{id:\d+}', [$company, 'delete']),
    ];
};
