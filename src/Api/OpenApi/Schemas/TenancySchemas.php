<?php

namespace Hub\Api\OpenApi\Schemas;

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
            'ApiUserListResponse' => CommonSchemas::collection('ApiUserItem'),
            'ApiUserWriteRequest' => [
                'type' => 'object',
                'required' => ['username', 'role'],
                'properties' => [
                    'username' => ['type' => 'string', 'example' => 'tenant-1001'],
                    'password' => ['type' => 'string', 'description' => 'Required on create; optional on update.'],
                    'role' => ['type' => 'string', 'enum' => ['hub_admin', 'license_client'], 'example' => 'license_client'],
                    'licenseRefId' => ['type' => 'integer', 'description' => 'Required for license_client; identifies one exact company/license row. Ignored for hub_admin.', 'example' => 1],
                    'enabled' => ['type' => 'boolean', 'example' => true],
                ],
            ],
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
            'CompanyWriteRequest' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string', 'example' => 'hitcare'],
                ],
            ],
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
            'LicenseWriteRequest' => [
                'type' => 'object',
                'required' => ['companyId', 'licenseId'],
                'properties' => [
                    'companyId' => ['type' => 'integer', 'example' => 1],
                    'licenseId' => ['type' => 'integer', 'example' => 1001],
                    'name' => ['type' => 'string', 'example' => 'gucc.dev'],
                ],
            ],
        ];
    }
}
