<?php

namespace Hub\Api\OpenApi\Schemas;

use Hub\Api\OpenApi\Responses;

/**
 * Os esquemas de envelope partilhados por todos os recursos.
 */
final class CommonSchemas
{
    /**
     * O envelope de colecção paginada que todos os endpoints de listagem usam.
     */
    public static function collection(string $itemSchema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => Responses::ref($itemSchema)],
                'pagination' => Responses::ref('CollectionPagination'),
                'filters' => Responses::ref('CollectionFilters'),
            ],
        ];
    }

    /**
     * Unpaginated {data: [...]} envelope.
     */
    public static function list(string $itemSchema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => Responses::ref($itemSchema)],
            ],
        ];
    }

    public static function schemas(): array
    {
        return [
            'StatusResponse' => [
                'type' => 'object',
                'required' => ['status'],
                'properties' => [
                    'status' => ['type' => 'string', 'example' => 'ok'],
                ],
            ],
            'IdCreateResponse' => [
                'type' => 'object',
                'required' => ['status', 'id'],
                'description' => 'Returned by the create endpoints that allocate a new row identifier.',
                'properties' => [
                    'status' => ['type' => 'string', 'example' => 'ok'],
                    'id' => ['type' => 'integer', 'example' => 1],
                ],
            ],
            'CollectionPagination' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'example' => 20],
                    'page' => ['type' => 'integer', 'example' => 1],
                    'total_pages' => ['type' => 'integer', 'example' => 3],
                    'total' => ['type' => 'integer', 'example' => 42],
                ],
            ],
            'CollectionFilters' => [
                'type' => 'object',
                'properties' => [
                    'applied' => ['type' => 'object', 'additionalProperties' => true],
                    'available' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
            ],
            'AuthTokenResponse' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'example' => 'ok'],
                    'token' => [
                        'type' => 'object',
                        'properties' => [
                            'access_token' => ['type' => 'string'],
                            'token_type' => ['type' => 'string', 'example' => 'Bearer'],
                            'username' => ['type' => 'string', 'example' => 'admin'],
                            'role' => ['type' => 'string', 'enum' => ['hub_admin', 'license_client'], 'example' => 'license_client'],
                            'license_id' => ['type' => 'integer', 'nullable' => true, 'example' => 1001],
                            'license_ref_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'Internal identifier of the exact company/license row.', 'example' => 1],
                            'company_id' => ['type' => 'integer', 'nullable' => true, 'example' => 1],
                            'company' => ['type' => 'string', 'nullable' => true, 'example' => 'hitcare'],
                            'expires_in' => ['type' => 'integer', 'example' => 3600],
                            'expires_at' => ['type' => 'string', 'example' => '2026-06-23T12:00:00Z'],
                            'refresh_token' => ['type' => 'string'],
                            'refresh_expires_in' => ['type' => 'integer', 'example' => 2592000],
                            'refresh_expires_at' => ['type' => 'string', 'example' => '2026-07-23T12:00:00Z'],
                        ],
                    ],
                ],
            ],
            'ErrorResponse' => [
                'type' => 'object',
                'required' => ['error'],
                'properties' => [
                    'error' => [
                        'type' => 'object',
                        'required' => ['code', 'message'],
                        'properties' => [
                            'code' => ['type' => 'string', 'example' => 'invalid_request'],
                            'message' => ['type' => 'string', 'example' => 'Validation failed'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
