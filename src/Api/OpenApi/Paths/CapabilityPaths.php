<?php

declare(strict_types=1);

namespace Hub\Api\OpenApi\Paths;

use Hub\Api\OpenApi\Parameters;
use Hub\Api\OpenApi\Requests;
use Hub\Api\OpenApi\Responses;
use Hub\Domain\DeviceTypeCatalog;

/**
 * O catálogo de capacidades por tipo de dispositivo, e as descobertas de capacidades.
 */
final class CapabilityPaths
{
    /** @return array<string, mixed> */
    public static function paths(): array
    {
        return self::catalog();
    }

    /** @return array<string, mixed> */
    private static function catalog(): array
    {
        return [
            '/api/capabilities' => [
                'get' => [
                    'tags' => ['Capabilities'],
                    'summary' => 'List device-type capability catalog',
                    'parameters' => [
                        // Do catálogo e não de uma lista à mão, para um tipo novo não ficar fora do contrato publicado.
                        Parameters::query('deviceType', [
                            'type' => 'string',
                            'enum' => DeviceTypeCatalog::keys(),
                        ]),
                    ],
                    'responses' => [
                        '200' => Responses::json('Capability catalog', 'CapabilityListResponse'),
                    ],
                ],
            ],
            '/api/capabilities/{id}' => [
                'get' => [
                    'tags' => ['Capabilities'],
                    'summary' => 'Get capability detail',
                    'parameters' => [Parameters::id('Capability ID')],
                    'responses' => Responses::map(
                        ['200' => Responses::json('Capability detail', 'CapabilityItem')],
                        'capability_not_found',
                    ),
                ],
            ],
        ];
    }
}
