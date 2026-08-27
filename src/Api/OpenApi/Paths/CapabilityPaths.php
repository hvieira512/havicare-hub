<?php

namespace Hub\Api\OpenApi\Paths;

use Hub\Api\OpenApi\Parameters;
use Hub\Api\OpenApi\Requests;
use Hub\Api\OpenApi\Responses;

/**
 * O catálogo de capacidades por tipo de dispositivo, e as descobertas de capacidades.
 */
final class CapabilityPaths
{
    public static function paths(): array
    {
        return array_merge(self::catalog(), self::discovery());
    }

    private static function catalog(): array
    {
        return [
            '/api/capabilities' => [
                'get' => [
                    'tags' => ['Capabilities'],
                    'summary' => 'List device-type capability catalog',
                    'parameters' => [
                        Parameters::query('deviceType', [
                            'type' => 'string',
                            'enum' => ['watch', 'ncs', 'radar', 'gateway', 'diaper_sensor', 'bracelet'],
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
                    'responses' => [
                        '200' => Responses::json('Capability detail', 'CapabilityItem'),
                        '404' => Responses::error(),
                    ],
                ],
            ],
        ];
    }

    private static function discovery(): array
    {
        $runId = Parameters::pathSchema('id', ['type' => 'string']);

        return [
            '/api/capability-discovery' => [
                'get' => [
                    'tags' => ['Discovery'],
                    'summary' => 'List capability discovery runs',
                    'responses' => [
                        '200' => Responses::json('Discovery run list', 'CapabilityDiscoveryListResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['Discovery'],
                    'summary' => 'Generate a capability discovery draft',
                    'requestBody' => Requests::inline([
                        'type' => 'object',
                        'required' => ['imei', 'modelId'],
                        'properties' => [
                            'imei' => ['type' => 'string'],
                            'modelId' => ['type' => 'integer'],
                        ],
                    ]),
                    'responses' => [
                        '200' => Responses::json('Discovery draft', 'CapabilityDiscoveryRun'),
                        '400' => Responses::error(),
                        '404' => Responses::error(),
                    ],
                ],
            ],
            '/api/capability-discovery/{id}' => [
                'get' => [
                    'tags' => ['Discovery'],
                    'summary' => 'Get a discovery run',
                    'parameters' => [$runId],
                    'responses' => [
                        '200' => Responses::json('Discovery run', 'CapabilityDiscoveryRun'),
                        '404' => Responses::error(),
                    ],
                ],
            ],
            '/api/capability-discovery/{id}/apply' => [
                'post' => [
                    'tags' => ['Discovery'],
                    'summary' => 'Apply a discovery draft to the model',
                    'parameters' => [$runId],
                    'responses' => [
                        '200' => Responses::json('Applied discovery run', 'CapabilityDiscoveryRun'),
                        '404' => Responses::error(),
                    ],
                ],
            ],
        ];
    }
}
