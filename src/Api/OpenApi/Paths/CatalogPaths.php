<?php

namespace Hub\Api\OpenApi\Paths;

use Hub\Api\OpenApi\Parameters;
use Hub\Api\OpenApi\Requests;
use Hub\Api\OpenApi\Responses;
use Hub\Domain\ProtocolRegistry;

/**
 * Fornecedores, modelos, tipos de dispositivo e protocolos.
 */
final class CatalogPaths
{
    public static function paths(): array
    {
        return array_merge(
            self::suppliers(),
            self::models(),
            self::deviceTypes(),
            self::protocols(),
        );
    }

    private static function suppliers(): array
    {
        return [
            '/api/suppliers' => [
                'get' => [
                    'tags' => ['Suppliers'],
                    'summary' => 'List suppliers',
                    'parameters' => Parameters::pagination(),
                    'responses' => [
                        '200' => Responses::json('Paginated supplier collection', 'SupplierListResponse'),
                    ],
                ],
            ],
        ];
    }

    private static function models(): array
    {
        $id = Parameters::id('Model ID');

        return [
            '/api/models' => [
                'get' => [
                    'tags' => ['Models'],
                    'summary' => 'List models',
                    'parameters' => array_merge(Parameters::pagination(), [
                        Parameters::stringQuery('supplier'),
                        Parameters::stringQuery('protocol'),
                        Parameters::stringQuery('deviceType'),
                        Parameters::query('model', [
                            'type' => 'string',
                            'description' => 'Filter by internal model or commercial name (exact match)',
                        ]),
                    ]),
                    'responses' => [
                        '200' => Responses::json('Paginated model collection', 'ModelListResponse'),
                    ],
                ],
                'post' => [
                    'tags' => ['Models'],
                    'summary' => 'Create model',
                    'requestBody' => Requests::multipartOrJson('ModelWriteRequest'),
                    'responses' => [
                        '200' => Responses::json('Model created', 'StatusResponse'),
                        '400' => Responses::error(),
                    ],
                ],
            ],
            '/api/models/template' => [
                'get' => [
                    'tags' => ['Models'],
                    'summary' => 'Get supplier capability template for a device type',
                    'description' => 'Returns the subset of device-type capabilities that a given supplier supports for the given device type, based on the supplier\'s protocol.',
                    'parameters' => [
                        [
                            'name' => 'supplierId',
                            'in' => 'query',
                            'required' => true,
                            'schema' => ['type' => 'integer', 'example' => 1],
                        ],
                        Parameters::query('deviceType', ['type' => 'string', 'default' => 'watch', 'example' => 'watch']),
                    ],
                    'responses' => [
                        '200' => Responses::json('Supplier capability template', 'ModelTemplateResponse'),
                        '400' => Responses::error(),
                    ],
                ],
            ],
            '/api/models/{id}' => [
                'get' => [
                    'tags' => ['Models'],
                    'summary' => 'Get model detail',
                    'parameters' => [$id],
                    'responses' => [
                        '200' => Responses::json('Model detail', 'ModelItem'),
                        '404' => Responses::error(),
                    ],
                ],
                'put' => [
                    'tags' => ['Models'],
                    'summary' => 'Update model',
                    'parameters' => [$id],
                    'requestBody' => Requests::multipartOrJson('ModelWriteRequest'),
                    'responses' => [
                        '200' => Responses::json('Model updated', 'StatusResponse'),
                        '400' => Responses::error(),
                        '409' => Responses::error(),
                    ],
                ],
                'delete' => [
                    'tags' => ['Models'],
                    'summary' => 'Delete model',
                    'parameters' => [$id],
                    'responses' => [
                        '200' => Responses::json('Model deleted', 'StatusResponse'),
                    ],
                ],
            ],
        ];
    }

    private static function deviceTypes(): array
    {
        return [
            '/api/device-types/suppliers' => [
                'get' => [
                    'tags' => ['Device Types'],
                    'summary' => 'List device types with their suppliers',
                    'responses' => [
                        '200' => Responses::json(
                            'Device type groups with their suppliers',
                            'DeviceTypeSuppliersModelsResponse',
                        ),
                    ],
                ],
            ],
            '/api/device-types/suppliers/models' => [
                'get' => [
                    'tags' => ['Device Types'],
                    'summary' => 'List device types, suppliers and models hierarchy',
                    'responses' => [
                        '200' => Responses::json(
                            'Full three-level hierarchy: device type → suppliers → models',
                            'DeviceTypeSuppliersModelsHierarchyResponse',
                        ),
                    ],
                ],
            ],
        ];
    }

    private static function protocols(): array
    {
        return [
            '/api/protocols/{protocol}/config-catalog' => [
                'get' => [
                    'tags' => ['Device Types'],
                    'summary' => 'Get config catalog for a device protocol',
                    'parameters' => [
                        Parameters::pathSchema('protocol', [
                            'type' => 'string',
                            'enum' => ProtocolRegistry::protocolsWithConfigCatalog(),
                        ]),
                    ],
                    'responses' => [
                        '200' => Responses::json(
                            'Config catalog entries for the protocol',
                            'ProtocolConfigCatalogResponse',
                        ),
                        '400' => Responses::error(),
                    ],
                ],
            ],
            '/api/protocols' => [
                'get' => [
                    'tags' => ['Device Types'],
                    'summary' => 'List known device protocols',
                    'responses' => [
                        '200' => Responses::json('Protocol registry entries', 'ProtocolListResponse'),
                    ],
                ],
            ],
        ];
    }
}
