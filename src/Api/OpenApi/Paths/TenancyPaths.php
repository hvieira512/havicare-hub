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
                    // O `columns` da resposta anuncia que colunas se ordenam, quais se
                    // editam e como se filtram; estes parâmetros são o outro lado disso.
                    'parameters' => array_merge(Parameters::pagination(), [
                        Parameters::stringQuery('role'),
                        Parameters::stringQuery('enabled'),
                        Parameters::stringQuery('username'),
                        Parameters::query('sort', [
                            'type' => 'string',
                            'example' => 'username:asc,role:desc',
                        ]),
                    ]),
                    'responses' => [
                        '200' => Responses::json('Paginated API user collection', 'ApiUserListResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['API Users'],
                    'summary' => 'Create API user',
                    'requestBody' => Requests::json('ApiUserCreateRequest'),
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
                    'requestBody' => Requests::json('ApiUserUpdateRequest'),
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
                    'parameters' => array_merge(Parameters::pagination(), [
                        Parameters::stringQuery('name'),
                        Parameters::query('sort', ['type' => 'string', 'example' => 'name:asc']),
                    ]),
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
                        'duplicate',
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
                        Parameters::query('company_id', ['type' => 'integer']),
                        // O nome antigo do mesmo filtro, mantido porque é público.
                        Parameters::query('companyId', ['type' => 'integer']),
                        Parameters::stringQuery('name'),
                        Parameters::stringQuery('company_name'),
                        Parameters::query('sort', ['type' => 'string', 'example' => 'company_name:asc,license_id:asc']),
                    ]),
                    'responses' => [
                        '200' => Responses::json('Paginated license collection', 'LicenseListResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['Licenses'],
                    'summary' => 'Create license',
                    'requestBody' => Requests::json('LicenseCreateRequest'),
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
                    'requestBody' => Requests::json('LicenseUpdateRequest'),
                    // O actualizar continua a herdar do que já lá está o que o pedido não
                    // trouxer, mas o que ele *trouxer* passa a ser validado: um `companyId` a
                    // zero era escrito na mesma, e a chave estrangeira rebentava depois -- o
                    // cliente levava um 500 no lugar da recusa que lhe pertencia.
                    'responses' => Responses::map(
                        ['200' => Responses::json('License updated', 'StatusResponse')],
                        'invalid_request',
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
            // O acesso à cloud do fabricante dos radares é de cada licença: com a conta de
            // uma, os radares das outras respondem que estão offline mesmo a publicar.
            '/api/licenses/{id}/radar-credentials' => [
                'get' => [
                    'tags' => ['Licenses'],
                    'summary' => 'Show radar cloud credentials for a license',
                    'parameters' => [$id],
                    'responses' => Responses::map(
                        ['200' => Responses::json('Radar credentials, without the secrets', 'RadarCredentialsResponse')],
                        'license_not_found',
                    ),
                ],
                'put' => [
                    'tags' => ['Licenses'],
                    'summary' => 'Store radar cloud credentials for a license',
                    'parameters' => [$id],
                    'requestBody' => Requests::json('RadarCredentialsWriteRequest'),
                    'responses' => Responses::map(
                        ['200' => Responses::json('Radar credentials stored', 'StatusResponse')],
                        'invalid_request',
                        'license_not_found',
                    ),
                ],
                'delete' => [
                    'tags' => ['Licenses'],
                    'summary' => 'Forget radar cloud credentials for a license',
                    'parameters' => [$id],
                    'responses' => Responses::map(
                        ['200' => Responses::json('Radar credentials forgotten', 'StatusResponse')],
                        'license_not_found',
                    ),
                ],
            ],
            // Autenticar não prova que a conta é desta licença: a de outra autentica à mesma e
            // só depois dá estes radares por offline. O que prova é quantos deles ela conhece.
            '/api/licenses/{id}/radar-credentials/check' => [
                'post' => [
                    'tags' => ['Licenses'],
                    'summary' => 'Try radar cloud credentials against this license radars',
                    'parameters' => [$id],
                    'requestBody' => Requests::json('RadarCredentialsWriteRequest'),
                    'responses' => Responses::map(
                        ['200' => Responses::json(
                            'How many of this license radars the account knows',
                            'RadarCredentialsCheckResponse',
                        )],
                        'license_not_found',
                    ),
                ],
            ],
        ];
    }
}
