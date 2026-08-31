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
                    'responses' => Responses::map(
                        ['200' => Responses::json('Capability detail', 'CapabilityItem')],
                        'capability_not_found',
                    ),
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
                    'responses' => Responses::map(
                        ['200' => Responses::json('Discovery draft', 'CapabilityDiscoveryRun')],
                        'invalid_request',
                        'model_not_found',
                    ),
                ],
            ],
            '/api/capability-discovery/{id}' => [
                'get' => [
                    'tags' => ['Discovery'],
                    'summary' => 'Get a discovery run',
                    'parameters' => [$runId],
                    'responses' => Responses::map(
                        ['200' => Responses::json('Discovery run', 'CapabilityDiscoveryRun')],
                        'discovery_not_found',
                    ),
                ],
            ],
            '/api/capability-discovery/{id}/apply' => [
                'post' => [
                    'tags' => ['Discovery'],
                    'summary' => 'Apply a discovery draft to the model',
                    'parameters' => [$runId],
                    // O `invalid_state` é a descoberta guardada sem o id do modelo a que
                    // pertence: existe, e mesmo assim não há onde a aplicar.
                    'responses' => Responses::map(
                        ['200' => Responses::json('Applied discovery run', 'CapabilityDiscoveryRun')],
                        'invalid_state',
                        'discovery_not_found',
                    ),
                ],
            ],
        ];
    }
}
