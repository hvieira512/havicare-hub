<?php

namespace Hub\Http;

class OpenApiSpec
{
    public static function get(): array
    {
        $imeiParam = [
            'name' => 'imei',
            'in' => 'path',
            'required' => true,
            'description' => 'Device IMEI',
            'schema' => ['type' => 'string', 'example' => '865028000000306'],
        ];

        $supplierIdParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'Supplier ID',
            'schema' => ['type' => 'integer', 'example' => 1],
        ];

        $modelIdParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'Model ID',
            'schema' => ['type' => 'integer', 'example' => 1],
        ];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Devices Hub Dashboard API',
                'version' => '1.0.0',
                'description' => 'Dashboard API for managing devices, suppliers and models.',
            ],
            'servers' => [['url' => 'http://localhost:8081']],
            'tags' => [
                ['name' => 'Dashboard'],
                ['name' => 'Devices'],
                ['name' => 'Suppliers'],
                ['name' => 'Models'],
                ['name' => 'System'],
            ],
            'paths' => [
                '/api/devices' => [
                    'get' => [
                        'tags' => ['Devices'],
                        'summary' => 'List devices',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 5]],
                            ['name' => 'deviceType', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'default' => 'all']],
                            ['name' => 'licenseId', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'default' => 'all']],
                            ['name' => 'supplier', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'default' => 'all']],
                            ['name' => 'model', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'default' => 'all']],
                            ['name' => 'q', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'default' => '']],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Paginated device collection',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceListResponse']]],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Devices'],
                        'summary' => 'Register device',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceCreateRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Device registered',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceCreateResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/devices/{imei}' => [
                    'get' => [
                        'tags' => ['Devices'],
                        'summary' => 'Get device detail',
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Device detail with commands and recent data',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceDetailResponse']]],
                            ],
                        ],
                    ],
                    'put' => [
                        'tags' => ['Devices'],
                        'summary' => 'Update device',
                        'parameters' => [$imeiParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceUpdateRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Device updated',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceUpdateResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'delete' => [
                        'tags' => ['Devices'],
                        'summary' => 'Delete device (unregister from whitelist)',
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Device deleted',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceDeleteResponse']]],
                            ],
                        ],
                    ],
                ],
                '/api/devices/{imei}/commands' => [
                    'post' => [
                        'tags' => ['Devices'],
                        'summary' => 'Send command to device',
                        'parameters' => [$imeiParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CommandRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Command result',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CommandResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/devices/{imei}/configuration' => [
                    'get' => [
                        'tags' => ['Devices'],
                        'summary' => 'Get device configuration catalog and state',
                        'parameters' => array_merge([$imeiParam], [
                            [
                                'name' => 'supplier',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'string'],
                            ],
                            [
                                'name' => 'model',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'string'],
                            ],
                        ]),
                        'responses' => [
                            '200' => [
                                'description' => 'Configuration catalog with desired and reported state',
                                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                            ],
                        ],
                    ],
                    'put' => [
                        'tags' => ['Devices'],
                        'summary' => 'Save and apply device configuration',
                        'parameters' => [$imeiParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'configs' => ['type' => 'object'],
                                    'supplier' => ['type' => 'string'],
                                    'model' => ['type' => 'string'],
                                ],
                                'required' => ['configs'],
                            ]]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Configuration saved and downlinks submitted',
                                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/devices/{imei}/configuration/{key}/apply' => [
                    'post' => [
                        'tags' => ['Devices'],
                        'summary' => 'Re-apply one stored device configuration item',
                        'parameters' => [
                            $imeiParam,
                            [
                                'name' => 'key',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'requestBody' => [
                            'required' => false,
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'supplier' => ['type' => 'string'],
                                    'model' => ['type' => 'string'],
                                ],
                            ]]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Configuration downlink submitted',
                                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/suppliers' => [
                    'get' => [
                        'tags' => ['Suppliers'],
                        'summary' => 'List suppliers',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 20]],
                            ['name' => 'enabled', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'default' => 'all', 'enum' => ['all', 'true', 'false']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Paginated supplier collection',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierListResponse']]],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Suppliers'],
                        'summary' => 'Create supplier',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierCreateRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Supplier created',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierCreateResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/suppliers/{id}' => [
                    'put' => [
                        'tags' => ['Suppliers'],
                        'summary' => 'Update supplier (rename or toggle enabled)',
                        'parameters' => [$supplierIdParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierUpdateRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Supplier updated',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StatusResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'delete' => [
                        'tags' => ['Suppliers'],
                        'summary' => 'Delete supplier (only if no models reference it)',
                        'parameters' => [$supplierIdParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Supplier deleted',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StatusResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/models' => [
                    'get' => [
                        'tags' => ['Models'],
                        'summary' => 'List models',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 20]],
                            ['name' => 'supplier', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'default' => 'all']],
                            ['name' => 'protocol', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'default' => 'all']],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Paginated model collection',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelListResponse']]],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Models'],
                        'summary' => 'Create model',
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'multipart/form-data' => ['schema' => ['$ref' => '#/components/schemas/ModelWriteRequest']],
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelWriteRequest']],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Model created',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StatusResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/models/{id}' => [
                    'put' => [
                        'tags' => ['Models'],
                        'summary' => 'Update model',
                        'parameters' => [$modelIdParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'multipart/form-data' => ['schema' => ['$ref' => '#/components/schemas/ModelWriteRequest']],
                                'application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelWriteRequest']],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Model updated',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StatusResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'delete' => [
                        'tags' => ['Models'],
                        'summary' => 'Delete model',
                        'parameters' => [$modelIdParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Model deleted',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StatusResponse']]],
                            ],
                        ],
                    ],
                ],
                '/api/openapi.json' => [
                    'get' => [
                        'tags' => ['System'],
                        'summary' => 'OpenAPI specification',
                        'responses' => ['200' => ['description' => 'OpenAPI document']],
                    ],
                ],
                '/api/docs' => [
                    'get' => [
                        'tags' => ['System'],
                        'summary' => 'Swagger UI',
                        'responses' => ['200' => ['description' => 'Swagger UI page']],
                    ],
                ],
            ],
            'components' => [
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
                'schemas' => [
                    'StatusResponse' => [
                        'type' => 'object',
                        'required' => ['status'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'ok'],
                        ],
                    ],
                    'SupplierCreateResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'id'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'ok'],
                            'id' => ['type' => 'integer', 'example' => 1],
                        ],
                    ],
                    'DeviceSummary' => [
                        'type' => 'object',
                        'properties' => [
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'model' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'licenseId' => ['type' => 'string', 'example' => '0'],
                            'protocol' => ['type' => 'string', 'nullable' => true, 'example' => 'wonlex-json'],
                            'online' => ['type' => 'boolean', 'example' => false],
                            'lastSeenAt' => ['type' => 'string', 'nullable' => true, 'example' => '2026-06-15T10:00:00Z'],
                        ],
                    ],
                    'ModelRef' => [
                        'type' => 'object',
                        'properties' => [
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'model' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'protocol' => ['type' => 'string', 'example' => 'wonlex-json'],
                            'image' => ['type' => 'string', 'example' => '/images/wonlex.png'],
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
                    'DeviceListResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DeviceSummary']],
                            'pagination' => ['$ref' => '#/components/schemas/CollectionPagination'],
                            'filters' => ['$ref' => '#/components/schemas/CollectionFilters'],
                        ],
                    ],
                    'DeviceDetail' => [
                        'type' => 'object',
                        'properties' => [
                            'imei' => ['type' => 'string'],
                            'supplier' => ['type' => 'string'],
                            'model' => ['type' => 'string'],
                            'deviceType' => ['type' => 'string'],
                            'licenseId' => ['type' => 'string'],
                            'protocol' => ['type' => 'string', 'nullable' => true],
                            'online' => ['type' => 'boolean'],
                            'lastSeenAt' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                    'CommandCatalogEntry' => [
                        'type' => 'object',
                        'properties' => [
                            'command' => ['type' => 'string', 'example' => 'dnHeartRate'],
                            'label' => ['type' => 'string', 'example' => 'Heart Rate'],
                            'icon' => ['type' => 'string', 'example' => 'fa-heart-pulse'],
                        ],
                    ],
                    'PendingCommand' => [
                        'type' => 'object',
                        'properties' => [
                            'dedupeKey' => ['type' => 'string'],
                            'command' => ['type' => 'string'],
                            'queuedAt' => ['type' => 'string'],
                            'expiresAt' => ['type' => 'string'],
                        ],
                    ],
                    'RecentItem' => [
                        'type' => 'object',
                        'properties' => [
                            'payload' => ['type' => 'object'],
                            'recorded_at' => ['type' => 'string'],
                        ],
                    ],
                    'RecentSection' => [
                        'type' => 'object',
                        'properties' => [
                            'raw' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/RecentItem']],
                            'telemetry' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/RecentItem']],
                            'events' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/RecentItem']],
                            'commands' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/RecentItem']],
                        ],
                    ],
                    'DeviceDetailResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'device' => ['$ref' => '#/components/schemas/DeviceDetail'],
                            'commands' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CommandCatalogEntry']],
                            'pending' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PendingCommand']],
                            'recent' => ['$ref' => '#/components/schemas/RecentSection'],
                        ],
                    ],
                    'DeviceCreateRequest' => [
                        'type' => 'object',
                        'required' => ['imei', 'supplier', 'model'],
                        'properties' => [
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'model' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'licenseId' => ['type' => 'string', 'example' => '0'],
                            'simNumber' => ['type' => 'string', 'example' => '+351912345678'],
                            'deviceId' => ['type' => 'string', 'example' => '8800000015'],
                        ],
                    ],
                    'DeviceCreateResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'imei'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'ok'],
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                        ],
                    ],
                    'DeviceUpdateRequest' => [
                        'type' => 'object',
                        'required' => ['imei', 'supplier', 'model'],
                        'properties' => [
                            'imei' => ['type' => 'string', 'example' => '865028000000307'],
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'model' => ['type' => 'string', 'example' => 'L08 Pro'],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'licenseId' => ['type' => 'string', 'example' => '0'],
                            'simNumber' => ['type' => 'string', 'example' => '+351912345678'],
                            'deviceId' => ['type' => 'string', 'example' => '8800000015'],
                        ],
                    ],
                    'DeviceUpdateResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'imei'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'ok'],
                            'imei' => ['type' => 'string', 'example' => '865028000000307'],
                        ],
                    ],
                    'DeviceDeleteResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'imei'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'ok'],
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                        ],
                    ],
                    'CommandRequest' => [
                        'type' => 'object',
                        'required' => ['command'],
                        'properties' => [
                            'command' => ['type' => 'string', 'example' => 'dnHeartRate'],
                        ],
                    ],
                    'CommandResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'sent'],
                            'command' => ['type' => 'object'],
                        ],
                    ],
                    'SupplierItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'name' => ['type' => 'string', 'example' => 'Wonlex'],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                            'model_count' => ['type' => 'integer', 'example' => 2],
                            'created_at' => ['type' => 'string'],
                            'updated_at' => ['type' => 'string'],
                        ],
                    ],
                    'SupplierListResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SupplierItem']],
                            'pagination' => ['$ref' => '#/components/schemas/CollectionPagination'],
                            'filters' => ['$ref' => '#/components/schemas/CollectionFilters'],
                        ],
                    ],
                    'SupplierCreateRequest' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string', 'example' => 'Wonlex'],
                        ],
                    ],
                    'SupplierUpdateRequest' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'example' => 'VIVISTAR'],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                        ],
                    ],
                    'ModelItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'supplier_id' => ['type' => 'integer', 'example' => 1],
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'model' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'protocol' => ['type' => 'string', 'example' => 'wonlex-json'],
                            'image' => ['type' => 'string', 'example' => '/images/wonlex.png'],
                        ],
                    ],
                    'ModelListResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ModelItem']],
                            'pagination' => ['$ref' => '#/components/schemas/CollectionPagination'],
                            'filters' => ['$ref' => '#/components/schemas/CollectionFilters'],
                        ],
                    ],
                    'ModelWriteRequest' => [
                        'type' => 'object',
                        'required' => ['supplier_id', 'model', 'protocol'],
                        'properties' => [
                            'supplier_id' => ['type' => 'integer', 'example' => 1],
                            'model' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'protocol' => ['type' => 'string', 'example' => 'wonlex-json'],
                            'image' => ['type' => 'string', 'format' => 'binary'],
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
                ],
            ],
        ];
    }
}
