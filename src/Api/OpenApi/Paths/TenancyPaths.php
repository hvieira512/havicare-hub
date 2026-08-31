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
    /**
     * Os erros que o criar e o actualizar de um utilizador partilham, porque partilham o
     * `ApiUserService::fields()` que os produz, mais o nome repetido que ambos recusam.
     *
     * @var list<string>
     */
    private const API_USER_WRITE_ERRORS = [
        'invalid_request',
        'invalid_role',
        'invalid_license',
        'user_exists',
    ];

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
                    'responses' => Responses::map(
                        ['201' => Responses::json('API user created', 'IdCreateResponse')],
                        ...self::API_USER_WRITE_ERRORS,
                    ),
                ],
            ],
            '/api/users/{id}' => [
                'put' => [
                    'tags' => ['API Users'],
                    'summary' => 'Update API user',
                    'parameters' => [$id],
                    'requestBody' => Requests::json('ApiUserWriteRequest'),
                    'responses' => Responses::map(
                        ['200' => Responses::json('API user updated', 'StatusResponse')],
                        ...self::API_USER_WRITE_ERRORS,
                        ...['user_not_found'],
                    ),
                ],
                'delete' => [
                    'tags' => ['API Users'],
                    'summary' => 'Delete API user',
                    'parameters' => [$id],
                    'responses' => Responses::map(
                        ['200' => Responses::json('API user deleted', 'StatusResponse')],
                        'user_not_found',
                    ),
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
                    // O 409 do nome repetido: o `duplicate` passou a 409 quando o
                    // `STATUS_BY_CODE` deixou de o inferir do nome, e este bloco continuou a
                    // prometer só 200 e 400. É o engano que o `Responses::map()` acaba.
                    'responses' => Responses::map(
                        ['200' => Responses::json('Company created', 'IdCreateResponse')],
                        'invalid_request',
                        'duplicate',
                    ),
                ],
            ],
            '/api/companies/{id}' => [
                'put' => [
                    'tags' => ['Companies'],
                    'summary' => 'Update company name',
                    'parameters' => [$id],
                    'requestBody' => Requests::json('CompanyWriteRequest'),
                    'responses' => Responses::map(
                        ['200' => Responses::json('Company updated', 'StatusResponse')],
                        'invalid_request',
                        'company_not_found',
                    ),
                ],
                'delete' => [
                    'tags' => ['Companies'],
                    'summary' => 'Delete company and its licenses',
                    'parameters' => [$id],
                    'responses' => Responses::map(
                        ['200' => Responses::json('Company deleted', 'StatusResponse')],
                        'company_not_found',
                    ),
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
                    'responses' => Responses::map(
                        ['200' => Responses::json('License created', 'IdCreateResponse')],
                        'invalid_request',
                    ),
                ],
            ],
            '/api/licenses/{id}' => [
                'put' => [
                    'tags' => ['Licenses'],
                    'summary' => 'Update license',
                    'parameters' => [$id],
                    'requestBody' => Requests::json('LicenseWriteRequest'),
                    // Sem 400: o actualizar herda do que já lá está o que o pedido não
                    // trouxer, e por isso não tem campo obrigatório para recusar.
                    'responses' => Responses::map(
                        ['200' => Responses::json('License updated', 'StatusResponse')],
                        'license_not_found',
                    ),
                ],
                'delete' => [
                    'tags' => ['Licenses'],
                    'summary' => 'Delete license',
                    'parameters' => [$id],
                    'responses' => Responses::map(
                        ['200' => Responses::json('License deleted', 'StatusResponse')],
                        'license_not_found',
                    ),
                ],
            ],
        ];
    }
}
