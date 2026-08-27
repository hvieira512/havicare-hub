<?php

namespace Hub\Api\OpenApi\Paths;

use Hub\Api\OpenApi\Parameters;
use Hub\Api\OpenApi\Requests;
use Hub\Api\OpenApi\Responses;

/**
 * Utilizadores da API, empresas e licenças.
 */
final class TenancyPaths
{
    public static function paths(): array
    {
        return array_merge(self::apiUsers(), self::companies(), self::licenses());
    }

    private static function apiUsers(): array
    {
        $id = Parameters::id('API user ID');

        return [
            '/api/users' => [
                'get' => [
                    'tags' => ['API Users'],
                    'summary' => 'List API users',
                    'parameters' => array_merge(Parameters::pagination(), [
                        Parameters::stringQuery('role'),
                        Parameters::stringQuery('enabled'),
                    ]),
                    'responses' => [
                        '200' => Responses::json('Paginated API user collection', 'ApiUserListResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['API Users'],
                    'summary' => 'Create API user',
                    'requestBody' => Requests::json('ApiUserWriteRequest'),
                    'responses' => [
                        '201' => Responses::json('API user created', 'IdCreateResponse'),
                        '400' => Responses::error(),
                        '409' => Responses::error(),
                    ],
                ],
            ],
            '/api/users/{id}' => [
                'put' => [
                    'tags' => ['API Users'],
                    'summary' => 'Update API user',
                    'parameters' => [$id],
                    'requestBody' => Requests::json('ApiUserWriteRequest'),
                    'responses' => [
                        '200' => Responses::json('API user updated', 'StatusResponse'),
                        '400' => Responses::error(),
                        '404' => Responses::error(),
                        '409' => Responses::error(),
                    ],
                ],
                'delete' => [
                    'tags' => ['API Users'],
                    'summary' => 'Delete API user',
                    'parameters' => [$id],
                    'responses' => [
                        '200' => Responses::json('API user deleted', 'StatusResponse'),
                        '404' => Responses::error(),
                    ],
                ],
            ],
        ];
    }

    private static function companies(): array
    {
        $id = Parameters::id('Company ID');

        return [
            '/api/companies' => [
                'get' => [
                    'tags' => ['Companies'],
                    'summary' => 'List companies',
                    'parameters' => Parameters::pagination(),
                    'responses' => [
                        '200' => Responses::json('Paginated company collection', 'CompanyListResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['Companies'],
                    'summary' => 'Create company',
                    'requestBody' => Requests::json('CompanyWriteRequest'),
                    'responses' => [
                        '200' => Responses::json('Company created', 'IdCreateResponse'),
                        '400' => Responses::error(),
                    ],
                ],
            ],
            '/api/companies/{id}' => [
                'put' => [
                    'tags' => ['Companies'],
                    'summary' => 'Update company name',
                    'parameters' => [$id],
                    'requestBody' => Requests::json('CompanyWriteRequest'),
                    'responses' => [
                        '200' => Responses::json('Company updated', 'StatusResponse'),
                        '400' => Responses::error(),
                        '404' => Responses::error(),
                    ],
                ],
                'delete' => [
                    'tags' => ['Companies'],
                    'summary' => 'Delete company and its licenses',
                    'parameters' => [$id],
                    'responses' => [
                        '200' => Responses::json('Company deleted', 'StatusResponse'),
                        '404' => Responses::error(),
                    ],
                ],
            ],
        ];
    }

    private static function licenses(): array
    {
        $id = Parameters::id('License ID');

        return [
            '/api/licenses' => [
                'get' => [
                    'tags' => ['Licenses'],
                    'summary' => 'List licenses',
                    'parameters' => array_merge(Parameters::pagination(), [
                        Parameters::query('companyId', ['type' => 'integer']),
                    ]),
                    'responses' => [
                        '200' => Responses::json('Paginated license collection', 'LicenseListResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['Licenses'],
                    'summary' => 'Create license',
                    'requestBody' => Requests::json('LicenseWriteRequest'),
                    'responses' => [
                        '200' => Responses::json('License created', 'IdCreateResponse'),
                        '400' => Responses::error(),
                    ],
                ],
            ],
            '/api/licenses/{id}' => [
                'put' => [
                    'tags' => ['Licenses'],
                    'summary' => 'Update license',
                    'parameters' => [$id],
                    'requestBody' => Requests::json('LicenseWriteRequest'),
                    'responses' => [
                        '200' => Responses::json('License updated', 'StatusResponse'),
                        '400' => Responses::error(),
                        '404' => Responses::error(),
                    ],
                ],
                'delete' => [
                    'tags' => ['Licenses'],
                    'summary' => 'Delete license',
                    'parameters' => [$id],
                    'responses' => [
                        '200' => Responses::json('License deleted', 'StatusResponse'),
                        '404' => Responses::error(),
                    ],
                ],
            ],
        ];
    }
}
