<?php

namespace Hub\Api\OpenApi\Schemas;

use Hub\Api\OpenApi\SchemaFromRequest;
use Hub\Api\Request\ApiUserWriteRequest;
use Hub\Api\Request\CompanyWriteRequest;
use Hub\Api\Request\LicenseWriteRequest;

/**
 * Utilizadores da API, empresas e licenças.
 */
final class TenancySchemas
{
    public static function schemas(): array
    {
        return array_merge(self::apiUsers(), self::companies(), self::licenses());
    }

    private static function apiUsers(): array
    {
        return [
            'ApiUserItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'username' => ['type' => 'string', 'example' => 'tenant-1001'],
                    'role' => ['type' => 'string', 'enum' => ['hub_admin', 'license_client'], 'example' => 'license_client'],
                    'license_id' => ['type' => 'integer', 'example' => 1001],
                    'license_ref_id' => ['type' => 'integer', 'nullable' => true, 'example' => 1],
                    'company_id' => ['type' => 'integer', 'nullable' => true, 'example' => 1],
                    'company' => ['type' => 'string', 'nullable' => true, 'example' => 'hitcare'],
                    'enabled' => ['type' => 'integer', 'example' => 1],
                    'created_at' => ['type' => 'string'],
                    'updated_at' => ['type' => 'string'],
                ],
            ],
            'ApiUserListResponse' => CommonSchemas::collection('ApiUserItem', withColumns: true),
            // Derivados do `ApiUserWriteRequest`, que é onde as regras vivem e correm. Este
            // bloco era escrito à mão e já não dizia o mesmo que o serviço: declarava o
            // `role` obrigatório, quando o serviço lhe dá `license_client` por omissão, e
            // não mencionava o `licenseId` nem o `companyId`, que o serviço lê para
            // encontrar a licença.
            'ApiUserCreateRequest' => SchemaFromRequest::schema(
                ApiUserWriteRequest::class,
                [ApiUserWriteRequest::GROUP_CREATE],
            ),
            // O mesmo corpo sem o grupo `create`: a palavra-passe deixa de ser obrigatória,
            // porque omiti-la a actualizar quer dizer "não mudar a palavra-passe".
            'ApiUserUpdateRequest' => SchemaFromRequest::schema(ApiUserWriteRequest::class),
        ];
    }

    private static function companies(): array
    {
        return [
            'CompanyItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'hitcare'],
                    'license_count' => ['type' => 'integer', 'example' => 1],
                    'created_at' => ['type' => 'string'],
                    'updated_at' => ['type' => 'string'],
                ],
            ],
            'CompanyListResponse' => CommonSchemas::collection('CompanyItem'),
            'CompanyWriteRequest' => SchemaFromRequest::schema(CompanyWriteRequest::class),
        ];
    }

    private static function licenses(): array
    {
        return [
            'LicenseItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'company_id' => ['type' => 'integer', 'example' => 1],
                    'company_name' => ['type' => 'string', 'example' => 'hitcare'],
                    'license_id' => ['type' => 'integer', 'example' => 1001],
                    'name' => ['type' => 'string', 'example' => 'gucc.dev'],
                    'created_at' => ['type' => 'string'],
                    'updated_at' => ['type' => 'string'],
                ],
            ],
            'LicenseListResponse' => CommonSchemas::collection('LicenseItem'),
            // O criar exige a empresa e a licença; o actualizar aceita a ausência de ambos
            // como "fica como está", e é por isso que passam a ser dois esquemas e não um.
            'LicenseCreateRequest' => SchemaFromRequest::schema(
                LicenseWriteRequest::class,
                [LicenseWriteRequest::GROUP_CREATE],
            ),
            'LicenseUpdateRequest' => SchemaFromRequest::schema(LicenseWriteRequest::class),
        ];
    }
}
