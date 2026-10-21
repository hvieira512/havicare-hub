<?php

declare(strict_types=1);

namespace Hub\Api\OpenApi\Schemas;

use Hub\Api\OpenApi\SchemaFromRequest;
use Hub\Api\Request\ApiUserWriteRequest;
use Hub\Api\Request\CompanyWriteRequest;
use Hub\Api\Request\LicenseWriteRequest;
use Hub\Api\Request\RadarCredentialsWriteRequest;

/**
 * Utilizadores da API, empresas e licenças.
 */
final class TenancySchemas
{
    /** @return array<string, mixed> */
    public static function schemas(): array
    {
        return array_merge(self::apiUsers(), self::companies(), self::licenses());
    }

    /** @return array<string, mixed> */
    private static function apiUsers(): array
    {
        return [
            'ApiUserItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'username' => ['type' => 'string', 'example' => 'tenant-1001'],
                    'role' => ['type' => 'string', 'enum' => ['hub_admin', 'license_client'], 'example' => 'license_client'],
                    'enabled' => ['type' => 'integer', 'enum' => [0, 1], 'example' => 1],
                    'licenseRefId' => ['type' => 'integer', 'nullable' => true, 'example' => 1],
                    'licenseId' => ['type' => 'integer', 'nullable' => true, 'example' => 1001],
                    'licenseName' => ['type' => 'string', 'nullable' => true, 'example' => 'gucc.dev'],
                    'companyId' => ['type' => 'integer', 'nullable' => true, 'example' => 1],
                    'companyName' => ['type' => 'string', 'nullable' => true, 'example' => 'hitcare'],
                    'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                    'updatedAt' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'ApiUserListResponse' => CommonSchemas::collection('ApiUserItem', withColumns: true),
            // Derivados do `ApiUserWriteRequest`, que é onde as regras vivem e correm.
            'ApiUserCreateRequest' => SchemaFromRequest::schema(
                ApiUserWriteRequest::class,
                [ApiUserWriteRequest::GROUP_CREATE],
            ),
            // O mesmo corpo sem o grupo `create`: a palavra-passe deixa de ser obrigatória,
            // porque omiti-la a actualizar quer dizer "não mudar a palavra-passe".
            'ApiUserUpdateRequest' => SchemaFromRequest::schema(ApiUserWriteRequest::class),
        ];
    }

    /** @return array<string, mixed> */
    private static function companies(): array
    {
        return [
            'CompanyItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'hitcare'],
                    'licenseCount' => ['type' => 'integer', 'example' => 1],
                    'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                    'updatedAt' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'CompanyListResponse' => CommonSchemas::collection('CompanyItem', withColumns: true),
            'CompanyWriteRequest' => SchemaFromRequest::schema(CompanyWriteRequest::class),
        ];
    }

    /** @return array<string, mixed> */
    private static function licenses(): array
    {
        return [
            'LicenseItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'companyId' => ['type' => 'integer', 'example' => 1],
                    'companyName' => ['type' => 'string', 'nullable' => true, 'example' => 'hitcare'],
                    'licenseId' => ['type' => 'integer', 'example' => 1001],
                    'name' => ['type' => 'string', 'example' => 'gucc.dev'],
                    'radarCloudConfigured' => ['type' => 'boolean', 'example' => false],
                    'deviceCount' => ['type' => 'integer', 'example' => 12],
                    'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                    'updatedAt' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'LicenseListResponse' => CommonSchemas::collection('LicenseItem', withColumns: true),
            // O criar exige a empresa e a licença; o actualizar lê a ausência de ambos como "fica como
            // está", e por isso são dois esquemas.
            'LicenseCreateRequest' => SchemaFromRequest::schema(
                LicenseWriteRequest::class,
                [LicenseWriteRequest::GROUP_CREATE],
            ),
            'LicenseUpdateRequest' => SchemaFromRequest::schema(LicenseWriteRequest::class),
            // Os segredos entram e não voltam a sair: a resposta diz se estão preenchidos e
            // mais nada. O `create` exige-os, porque metade das credenciais nunca autentica.
            'RadarCredentialsResponse' => [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'configured' => ['type' => 'boolean', 'example' => true],
                            'baseUrl' => ['type' => 'string', 'example' => 'https://radarconsole.com/prod-api'],
                            'username' => ['type' => 'string', 'example' => 'casabranca'],
                            'appId' => ['type' => 'string'],
                            'hasPassword' => ['type' => 'boolean', 'example' => true],
                            'hasAppSecret' => ['type' => 'boolean', 'example' => true],
                            'updatedAt' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                ],
            ],
            'RadarCredentialsWriteRequest' => SchemaFromRequest::schema(
                RadarCredentialsWriteRequest::class,
                [RadarCredentialsWriteRequest::GROUP_CREATE],
            ),
            'RadarCredentialsCheckResponse' => [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'radars' => ['type' => 'integer', 'example' => 21],
                            'responding' => ['type' => 'integer', 'example' => 21],
                            'error' => ['type' => 'string', 'nullable' => true, 'enum' => ['login_failed', null]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
