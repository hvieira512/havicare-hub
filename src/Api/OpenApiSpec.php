<?php

namespace Hub\Api;

use Hub\Api\OpenApi\Paths\CapabilityPaths;
use Hub\Api\OpenApi\Paths\CatalogPaths;
use Hub\Api\OpenApi\Paths\DevicePaths;
use Hub\Api\OpenApi\Paths\SystemPaths;
use Hub\Api\OpenApi\Paths\TenancyPaths;
use Hub\Api\OpenApi\Schemas\CapabilitySchemas;
use Hub\Api\OpenApi\Schemas\CatalogSchemas;
use Hub\Api\OpenApi\Schemas\CommonSchemas;
use Hub\Api\OpenApi\Schemas\DeviceSchemas;
use Hub\Api\OpenApi\Schemas\DiscoverySchemas;
use Hub\Api\OpenApi\Schemas\NotificationSchemas;
use Hub\Api\OpenApi\Schemas\TenancySchemas;

/**
 * Assembles the dashboard OpenAPI document from the per-domain path and schema
 * definitions in Hub\Api\OpenApi.
 */
class OpenApiSpec
{
    public static function get(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Devices Hub Dashboard API',
                'version' => '1.0.0',
                'description' => 'Dashboard API for managing devices, suppliers and models.',
            ],
            'servers' => [['url' => '/']],
            'security' => [
                ['bearerAuth' => []],
            ],
            'tags' => [
                ['name' => 'Suppliers'],
                ['name' => 'Models'],
                ['name' => 'Capabilities'],
                ['name' => 'Companies'],
                ['name' => 'Licenses'],
                ['name' => 'Devices'],
                ['name' => 'API Users'],
                ['name' => 'Notifications'],
                ['name' => 'System'],
                ['name' => 'Device Types'],
                ['name' => 'Discovery'],
            ],
            'paths' => array_merge(
                SystemPaths::paths(),
                DevicePaths::paths(),
                CatalogPaths::paths(),
                CapabilityPaths::paths(),
                TenancyPaths::paths(),
            ),
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                        'description' => 'Use the bearer token returned by /api/auth/login.',
                    ],
                ],
                'responses' => [
                    'Error' => [
                        'description' => 'Error response',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                            ],
                        ],
                    ],
                ],
                'schemas' => array_merge(
                    CommonSchemas::schemas(),
                    DeviceSchemas::schemas(),
                    CapabilitySchemas::schemas(),
                    DiscoverySchemas::schemas(),
                    CatalogSchemas::schemas(),
                    TenancySchemas::schemas(),
                    NotificationSchemas::schemas(),
                ),
            ],
        ];
    }
}
