<?php

namespace Hub\Api;

use Hub\Api\OpenApi\Paths\CapabilityPaths;
use Hub\Api\OpenApi\Paths\CatalogPaths;
use Hub\Api\OpenApi\Paths\DevicePaths;
use Hub\Api\OpenApi\Paths\StreamPaths;
use Hub\Api\OpenApi\Paths\SystemPaths;
use Hub\Api\OpenApi\Paths\TenancyPaths;
use Hub\Api\OpenApi\Responses;
use Hub\Api\OpenApi\Schemas\CapabilitySchemas;
use Hub\Api\OpenApi\Schemas\CatalogSchemas;
use Hub\Api\OpenApi\Schemas\CommonSchemas;
use Hub\Api\OpenApi\Schemas\DeviceSchemas;
use Hub\Api\OpenApi\Schemas\DiscoverySchemas;
use Hub\Api\OpenApi\Schemas\NotificationSchemas;
use Hub\Api\OpenApi\Schemas\TenancySchemas;

/**
 * Monta o documento OpenAPI da dashboard a partir das definições de rotas e de esquemas por
 * domínio em `Hub\Api\OpenApi`.
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
            'paths' => self::withAmbientErrors(array_merge(
                SystemPaths::paths(),
                DevicePaths::paths(),
                CatalogPaths::paths(),
                CapabilityPaths::paths(),
                TenancyPaths::paths(),
                StreamPaths::paths(),
            )),
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

    /**
     * Os dois erros que qualquer rota devolve sem os declarar: o `ApiKernel` responde 401
     * antes de haver rota e 500 quando uma excepção sobe até ele.
     *
     * Acrescentam-se aqui porque a regra é do kernel: o 500 vale para todas, e o 401 para
     * todas as que não sejam públicas -- o que o `security: []` da operação marca. O `+`
     * preserva o que uma rota já declare.
     *
     * @param array<string, mixed> $paths
     * @return array<string, mixed>
     */
    private static function withAmbientErrors(array $paths): array
    {
        foreach ($paths as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (!is_array($operation) || !isset($operation['responses'])) {
                    continue;
                }

                $ambient = ['500' => Responses::error()];
                if (($operation['security'] ?? null) !== []) {
                    $ambient['401'] = Responses::error();
                }

                $responses = $operation['responses'] + $ambient;
                ksort($responses);
                $paths[$path][$method]['responses'] = $responses;
            }
        }

        return $paths;
    }
}
