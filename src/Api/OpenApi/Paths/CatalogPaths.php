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
    /**
     * Os erros que o criar e o actualizar de um modelo partilham, porque partilham o
     * `ModelService::modelFields()` e o `storeModelImage()` que os produzem, mais o par
     * fornecedor+modelo repetido que ambos recusam.
     *
     * @var list<string>
     */
    private const MODEL_WRITE_ERRORS = [
        'invalid_request',
        'supplier_not_found',
        'model_exists',
        'unknown_protocol',
        'unsupported_capability',
        'invalid_requestable_capability',
        'upload_failed',
        'image_too_large',
        'gd_missing',
        'gd_jpeg_missing',
        'invalid_image',
        'image_save_failed',
    ];

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
                    'responses' => Responses::map(
                        ['200' => Responses::json('Model created', 'StatusResponse')],
                        ...self::MODEL_WRITE_ERRORS,
                    ),
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
                    'responses' => Responses::map(
                        ['200' => Responses::json('Supplier capability template', 'ModelTemplateResponse')],
                        'invalid_request',
                        'supplier_not_found',
                        'unknown_protocol',
                    ),
                ],
            ],
            '/api/models/{id}' => [
                'get' => [
                    'tags' => ['Models'],
                    'summary' => 'Get model detail',
                    'parameters' => [$id],
                    'responses' => Responses::map(
                        ['200' => Responses::json('Model detail', 'ModelItem')],
                        'model_not_found',
                    ),
                ],
                'put' => [
                    'tags' => ['Models'],
                    'summary' => 'Update model',
                    'parameters' => [$id],
                    'requestBody' => Requests::multipartOrJson('ModelWriteRequest'),
                    'responses' => Responses::map(
                        ['200' => Responses::json('Model updated', 'StatusResponse')],
                        ...self::MODEL_WRITE_ERRORS,
                        ...['model_not_found'],
                    ),
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
                    'responses' => Responses::map(
                        [
                            '200' => Responses::json(
                                'Config catalog entries for the protocol',
                                'ProtocolConfigCatalogResponse',
                            ),
                        ],
                        'protocol_not_found',
                    ),
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
