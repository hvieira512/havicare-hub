<?php

namespace Hub\Api;

use Hub\Domain\ProtocolRegistry;

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

        $capabilityIdParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'Capability ID',
            'schema' => ['type' => 'integer', 'example' => 1],
        ];

        $commandIdParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'Command ID',
            'schema' => ['type' => 'string', 'example' => '9f395f4f04fe589e'],
        ];

        $apiUserIdParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'API user ID',
            'schema' => ['type' => 'integer', 'example' => 1],
        ];

        $companyIdParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'Company ID',
            'schema' => ['type' => 'integer', 'example' => 1],
        ];

        $licenseIdParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'License ID',
            'schema' => ['type' => 'integer', 'example' => 1],
        ];

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
            'paths' => [
                '/api/auth/login' => [
                    'post' => [
                        'tags' => ['System'],
                        'summary' => 'Issue bearer token for API access',
                        'security' => [],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'username' => ['type' => 'string'],
                                    'password' => ['type' => 'string'],
                                    'refresh_token' => ['type' => 'string'],
                                ],
                                'description' => 'Provide username and password for initial login, or refresh_token to issue a new token pair.',
                            ]]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Bearer and refresh tokens issued',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/AuthTokenResponse']]],
                            ],
                            '401' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/devices' => [
                    'get' => [
                        'tags' => ['Devices'],
                        'summary' => 'List devices',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 5]],
							['name' => 'deviceType', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
							['name' => 'licenseId', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
							['name' => 'supplier', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
							['name' => 'model', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
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
                                'description' => 'Device detail with desired/effective configuration and synchronization lifecycle',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceDetailResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '403' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'put' => [
                        'tags' => ['Devices'],
                        'summary' => 'Update device metadata',
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
                            '403' => ['$ref' => '#/components/responses/Error'],
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
                '/api/devices/{imei}/configurations' => [
                    'patch' => [
                        'tags' => ['Devices'],
                        'summary' => 'Partially update device configurations',
                        'parameters' => [$imeiParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceConfigurationsUpdateRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Device configurations updated',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceConfigurationsUpdateResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '403' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/devices/{imei}/requests' => [
                    'post' => [
                        'tags' => ['Devices'],
                        'summary' => 'Request generic telemetry feature from device',
                        'parameters' => [$imeiParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/TelemetryRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Telemetry request result',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/TelemetryRequestResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/devices/{imei}/association' => [
                    'patch' => [
                        'tags' => ['Devices'],
                        'summary' => 'Associate a registered device to a company and license',
                        'parameters' => [$imeiParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceAssociationRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Device association updated. If the company exists but the requested license does not, the license is created automatically for that company.',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceAssociationResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '403' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'delete' => [
                        'tags' => ['Devices'],
                        'summary' => 'Remove the current company and license association from a device',
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Device association removed',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceAssociationResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/devices/{imei}/stream' => [
                    'get' => [
                        'tags' => ['Devices'],
                        'summary' => 'Open a server-sent events stream for recent device activity',
                        'parameters' => [$imeiParam],
                        'responses' => [
                            '200' => [
                                'description' => 'SSE stream emitting snapshot and update events',
                                'content' => ['text/event-stream' => ['schema' => ['$ref' => '#/components/schemas/DeviceStreamResponse']]],
                            ],
                            '403' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/commands/{id}' => [
                    'get' => [
                        'tags' => ['Devices'],
                        'summary' => 'Get command lifecycle by command ID',
                        'parameters' => [$commandIdParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Command detail with associated device',
                                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
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
							['name' => 'enabled', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['true', 'false']]],
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
								['name' => 'supplier', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
								['name' => 'protocol', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
								['name' => 'deviceType', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
								['name' => 'model', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'description' => 'Filter by internal model or commercial name (exact match)']],
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
                '/api/device-types/suppliers' => [
                    'get' => [
                        'tags' => ['Device Types'],
                        'summary' => 'List device types with their suppliers',
                        'responses' => [
                            '200' => [
                                'description' => 'Device type groups with their suppliers',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceTypeSuppliersModelsResponse']]],
                            ],
                        ],
                    ],
                ],
                '/api/device-types/suppliers/models' => [
                    'get' => [
                        'tags' => ['Device Types'],
                        'summary' => 'List device types, suppliers and models hierarchy',
                        'responses' => [
                            '200' => [
                                'description' => 'Full three-level hierarchy: device type → suppliers → models',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceTypeSuppliersModelsHierarchyResponse']]],
                            ],
                        ],
                    ],
                ],
                '/api/models/template' => [
                    'get' => [
                        'tags' => ['Models'],
                        'summary' => 'Get supplier capability template for a device type',
                        'description' => 'Returns the subset of device-type capabilities that a given supplier supports for the given device type, based on the supplier\'s protocol.',
                        'parameters' => [
                            ['name' => 'supplierId', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'example' => 1]],
                            ['name' => 'deviceType', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'default' => 'watch', 'example' => 'watch']],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Supplier capability template',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelTemplateResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/models/{id}' => [
                    'get' => [
                        'tags' => ['Models'],
                        'summary' => 'Get model detail',
                        'parameters' => [$modelIdParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Model detail',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ModelItem']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
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
                '/api/protocols/{protocol}/config-catalog' => [
                    'get' => [
                        'tags' => ['Device Types'],
                        'summary' => 'Get config catalog for a device protocol',
                        'parameters' => [
                            ['name' => 'protocol', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ProtocolRegistry::protocolsWithConfigCatalog()]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Config catalog entries for the protocol',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ProtocolConfigCatalogResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/protocols' => [
                    'get' => [
                        'tags' => ['Device Types'],
                        'summary' => 'List known device protocols',
                        'responses' => [
                            '200' => [
                                'description' => 'Protocol registry entries',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ProtocolListResponse']]],
                            ],
                        ],
                    ],
                ],
                '/api/openapi.json' => [
                    'get' => [
                        'tags' => ['System'],
                        'summary' => 'OpenAPI specification',
                        'security' => [],
                        'responses' => ['200' => ['description' => 'OpenAPI document']],
                    ],
                ],
                '/api/notifications' => [
                    'get' => [
                        'tags' => ['Notifications'],
                        'summary' => 'List dashboard notifications',
                        'parameters' => [
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 20, 'maximum' => 100]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Latest dashboard notifications and global unread count',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DashboardNotificationListResponse']]],
                            ],
                        ],
                    ],
                ],
                '/api/notifications/read' => [
                    'patch' => [
                        'tags' => ['Notifications'],
                        'summary' => 'Mark dashboard notifications as globally read',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DashboardNotificationReadRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Notifications marked read',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DashboardNotificationReadResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/notifications/{id}' => [
                    'delete' => [
                        'tags' => ['Notifications'],
                        'summary' => 'Delete a dashboard notification',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Notification deleted',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DashboardNotificationReadResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/users' => [
                    'get' => [
                        'tags' => ['API Users'],
                        'summary' => 'List API users',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 20]],
							['name' => 'role', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
							['name' => 'enabled', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Paginated API user collection',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiUserListResponse']]],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['API Users'],
                        'summary' => 'Create API user',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiUserWriteRequest']]],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'API user created',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SupplierCreateResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/users/{id}' => [
                    'put' => [
                        'tags' => ['API Users'],
                        'summary' => 'Update API user',
                        'parameters' => [$apiUserIdParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiUserWriteRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'API user updated',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StatusResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'delete' => [
                        'tags' => ['API Users'],
                        'summary' => 'Delete API user',
                        'parameters' => [$apiUserIdParam],
                        'responses' => [
                            '200' => [
                                'description' => 'API user deleted',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StatusResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/capabilities' => [
                    'get' => [
                        'tags' => ['Capabilities'],
                        'summary' => 'List device-type capability catalog',
                        'parameters' => [
                            ['name' => 'deviceType', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['watch', 'ncs', 'radar']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Capability catalog',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CapabilityListResponse']]],
                            ],
                        ],
                    ],
                ],
                '/api/capabilities/{id}' => [
                    'get' => [
                        'tags' => ['Capabilities'],
                        'summary' => 'Get capability detail',
                        'parameters' => [$capabilityIdParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Capability detail',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CapabilityItem']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/capability-discovery' => [
                    'get' => [
                        'tags' => ['Discovery'],
                        'summary' => 'List capability discovery runs',
                        'responses' => [
                            '200' => [
                                'description' => 'Discovery run list',
                                'content' => ['application/json' => ['schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => ['type' => 'array', 'items' => ['type' => 'object']],
                                    ],
                                ]]],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Discovery'],
                        'summary' => 'Generate a capability discovery draft',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['imei', 'modelId'],
                                'properties' => [
                                    'imei' => ['type' => 'string'],
                                    'modelId' => ['type' => 'integer'],
                                ],
                            ]]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Discovery draft',
                                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/capability-discovery/{id}' => [
                    'get' => [
                        'tags' => ['Discovery'],
                        'summary' => 'Get a discovery run',
                        'parameters' => [[
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ]],
                        'responses' => [
                            '200' => [
                                'description' => 'Discovery run',
                                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/capability-discovery/{id}/apply' => [
                    'post' => [
                        'tags' => ['Discovery'],
                        'summary' => 'Apply a discovery draft to the model',
                        'parameters' => [[
                            'name' => 'id',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ]],
                        'responses' => [
                            '200' => [
                                'description' => 'Applied discovery run',
                                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/companies' => [
                    'get' => [
                        'tags' => ['Companies'],
                        'summary' => 'List companies',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 20]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Paginated company collection',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CompanyListResponse']]],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Companies'],
                        'summary' => 'Create company',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CompanyWriteRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Company created',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CompanyCreateResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/companies/{id}' => [
                    'put' => [
                        'tags' => ['Companies'],
                        'summary' => 'Update company name',
                        'parameters' => [$companyIdParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CompanyWriteRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Company updated',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StatusResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'delete' => [
                        'tags' => ['Companies'],
                        'summary' => 'Delete company and its licenses',
                        'parameters' => [$companyIdParam],
                        'responses' => [
                            '200' => [
                                'description' => 'Company deleted',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StatusResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/licenses' => [
                    'get' => [
                        'tags' => ['Licenses'],
                        'summary' => 'List licenses',
                        'parameters' => [
                            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]],
                            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 20]],
							['name' => 'companyId', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Paginated license collection',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/LicenseListResponse']]],
                            ],
                        ],
                    ],
                    'post' => [
                        'tags' => ['Licenses'],
                        'summary' => 'Create license',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/LicenseWriteRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'License created',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/LicenseCreateResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/licenses/{id}' => [
                    'put' => [
                        'tags' => ['Licenses'],
                        'summary' => 'Update license',
                        'parameters' => [$licenseIdParam],
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/LicenseWriteRequest']]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'License updated',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StatusResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                    'delete' => [
                        'tags' => ['Licenses'],
                        'summary' => 'Delete license',
                        'parameters' => [$licenseIdParam],
                        'responses' => [
                            '200' => [
                                'description' => 'License deleted',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StatusResponse']]],
                            ],
                            '404' => ['$ref' => '#/components/responses/Error'],
                        ],
                    ],
                ],
                '/api/docs' => [
                    'get' => [
                        'tags' => ['System'],
                        'summary' => 'Swagger UI',
                        'security' => [],
                        'responses' => ['200' => ['description' => 'Swagger UI page']],
                    ],
                ],
            ],
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
                            'company' => ['type' => 'string', 'example' => 'hitcare'],
                            'licenseId' => ['type' => 'integer', 'example' => 0],
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
                            'commercialName' => ['type' => 'string', 'example' => 'HW20PRO'],
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
                            'company' => ['type' => 'string'],
                            'licenseId' => ['type' => 'integer', 'example' => 1001],
                            'simNumber' => ['type' => 'string'],
                            'deviceId' => ['type' => 'string'],
                            'online' => ['type' => 'boolean'],
                            'lastSeenAt' => ['type' => 'string', 'nullable' => true],
                            'lastStateAt' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                    'CommandCatalogEntry' => [
                        'type' => 'object',
                        'properties' => [
                            'command' => ['type' => 'string', 'example' => 'heart_rate'],
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
                    'CommandRecord' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                            'imei' => ['type' => 'string'],
                            'protocol' => ['type' => 'string'],
                            'nativeType' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'feature' => ['type' => 'string'],
                            'configKey' => ['type' => 'string'],
                            'expectedReplyTypes' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'requestedAt' => ['type' => 'string'],
                            'sentAt' => ['type' => 'string', 'nullable' => true],
                            'updatedAt' => ['type' => 'string'],
                            'ackedAt' => ['type' => 'string', 'nullable' => true],
                            'error' => ['type' => 'string', 'nullable' => true],
                            'replyNativeType' => ['type' => 'string', 'nullable' => true],
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
                            'commands' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CommandRecord']],
                        ],
                    ],
                    'DeviceDetailResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'device' => ['$ref' => '#/components/schemas/DeviceDetail'],
                            'model' => ['$ref' => '#/components/schemas/ModelDetail'],
                            'configuration' => ['$ref' => '#/components/schemas/DeviceConfigurationSummary'],
                            'configurations' => [
                                'type' => 'object',
                                'description' => 'Desired generic configuration values stored by the Hub.',
                            ],
                            'effectiveConfigurations' => [
                                'type' => 'object',
                                'description' => 'Configuration values confirmed as effective by the device contract.',
                            ],
                            'configurationSync' => ['$ref' => '#/components/schemas/ConfigurationSync'],
                            'capabilities' => ['$ref' => '#/components/schemas/DeviceCapabilitiesMatrix'],
                        ],
                    ],
                    'ModelDetail' => [
                        'type' => 'object',
                        'nullable' => true,
                        'properties' => [
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'internalModel' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'commercialName' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'image' => ['type' => 'string', 'nullable' => true, 'example' => '/model-images/abc123.jpg'],
                        ],
                    ],
                    'DeviceRecentResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'telemetry' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/RecentItem']],
                            'events' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/RecentItem']],
                            'commands' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CommandRecord']],
                        ],
                    ],
                    'DeviceStreamResponse' => [
                        'type' => 'string',
                        'example' => "event: snapshot\ndata: {\"telemetry\":[],\"events\":[],\"commands\":[]}\n\n",
                    ],
                    'DeviceConfigurationSummary' => [
                        'type' => 'object',
                        'properties' => [
                            'supported' => ['type' => 'integer', 'example' => 12],
                            'stored' => ['type' => 'integer', 'example' => 3],
                        ],
                    ],
                    'DeviceTelemetryCapabilitiesSection' => [
                        'type' => 'object',
                        'additionalProperties' => ['$ref' => '#/components/schemas/DeviceTelemetryCapability'],
                        'example' => [
                            'heart_rate' => ['supported' => true, 'requestable' => true],
                            'location' => ['supported' => true, 'requestable' => true],
                        ],
                    ],
                    'DeviceTelemetryCapability' => [
                        'type' => 'object',
                        'properties' => [
                            'supported' => ['type' => 'boolean'],
                            'requestable' => ['type' => 'boolean'],
                        ],
                        'required' => ['supported', 'requestable'],
                    ],
                    'CapabilityOption' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => ['oneOf' => [['type' => 'integer'], ['type' => 'string']]],
                            'label' => ['type' => 'string'],
                            'fields' => [
                                'type' => 'object',
                                'nullable' => true,
                                'additionalProperties' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['type' => 'string'],
                                        'min' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'CapabilityMetaField' => [
                        'type' => 'object',
                        'properties' => [
                            'options' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CapabilityOption']],
                        ],
                    ],
                    'CapabilityWithMeta' => [
                        'type' => 'object',
                        'properties' => [
                            'value' => ['type' => 'object'],
                            '_meta' => [
                                'type' => 'object',
                                'properties' => [
                                    'limit' => ['type' => 'integer'],
                                ],
                                'additionalProperties' => ['$ref' => '#/components/schemas/CapabilityMetaField'],
                            ],
                        ],
                    ],
                    'AlarmClockRecurrence' => [
                        'type' => 'object',
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => ['once', 'daily', 'custom']],
                            'days' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                            ],
                        ],
                        'required' => ['kind'],
                    ],
                    'AlarmClockItem' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string', 'nullable' => true, 'description' => 'Optional alarm name supported by Wonlex and omitted when unsupported by the supplier.'],
                            'time' => ['type' => 'string', 'example' => '12:10'],
                            'enabled' => ['type' => 'boolean'],
                            'type' => ['type' => 'integer', 'nullable' => true, 'example' => 1, 'description' => 'Required for Vivistar alarm_clock and omitted for 4P Touch.'],
                            'recurrence' => ['$ref' => '#/components/schemas/AlarmClockRecurrence'],
                            'url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true, 'description' => 'Optional HTTP(S) voice-reminder audio URL supported by Wonlex.'],
                        ],
                        'required' => ['time', 'enabled'],
                    ],
                    'AlarmClockMeta' => [
                        'type' => 'object',
                        'properties' => [
                            'limit' => ['type' => 'integer', 'example' => 3],
                            'recurrence' => [
                                'type' => 'object',
                                'description' => 'Public recurrence options for alarm_clock: once, daily and custom.',
                                'properties' => [
                                    'options' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => '#/components/schemas/CapabilityOption'],
                                    ],
                                ],
                            ],
                            'days' => [
                                'type' => 'object',
                                'properties' => [
                                    'options' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => '#/components/schemas/CapabilityOption'],
                                    ],
                                ],
                            ],
                            'type' => [
                                'type' => 'object',
                                'properties' => [
                                    'options' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => '#/components/schemas/CapabilityOption'],
                                    ],
                                ],
                            ],
                            'label' => ['$ref' => '#/components/schemas/CapabilityMetaField'],
                            'url' => ['$ref' => '#/components/schemas/CapabilityMetaField'],
                        ],
                    ],
                    'AlarmClockCapability' => [
                        'type' => 'object',
                        'description' => 'Public generic watch alarm clock capability exposed by GET /api/devices/{imei}. It is present whenever the model supports alarm_clock, even if no saved configuration exists yet. Vivistar exposes type metadata and requires type on PATCH; 4P Touch does not.',
                        'properties' => [
                            'value' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/AlarmClockItem'],
                            ],
                            '_meta' => ['$ref' => '#/components/schemas/AlarmClockMeta'],
                        ],
                        'required' => ['value'],
                    ],
                    'AlarmClockConfiguration' => [
                        'type' => 'object',
                        'description' => 'Payload accepted by PATCH /api/devices/{imei}/configurations under configurations.alarm_clock. Send items as an array of alarms; an empty array is valid and clears the saved alarms. Include type only for Vivistar, and omit it for 4P Touch. Wonlex optionally supports label and an HTTP(S) audio URL, and supports only daily or custom recurrence because its protocol is a weekly Monday-to-Sunday mask.',
                        'properties' => [
                            'items' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/AlarmClockItem'],
                            ],
                        ],
                        'required' => ['items'],
                    ],
                    'PhonebookConfiguration' => [
                        'type' => 'object',
                        'description' => 'Payload accepted under configurations.phonebook for Wonlex and 4P Touch. Send only generic name and international phone fields; native IDs, area codes and SOS flags are managed by the hub. Wonlex accepts up to 10 contacts and stores the first 4 characters of each name; 4P Touch accepts up to 5 contacts and stores the first 10 characters. An empty array clears the phonebook.',
                        'properties' => [
                            'contacts' => [
                                'type' => 'array',
                                'maxItems' => 10,
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['phone', 'name'],
                                    'properties' => [
                                        'phone' => [
                                            'type' => 'string',
                                            'maxLength' => 20,
                                            'description' => 'ASCII only.',
                                        ],
                                        'name' => [
                                            'type' => 'string',
                                            'description' => 'Unicode string. The hub truncates it to the supplier limit exposed in the phonebook capability metadata.',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'required' => ['contacts'],
                    ],
                    'DeviceConfiguredCapabilitiesSection' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'description' => 'Normalized writable capabilities for a section. Supported capabilities are present even when the device has no stored configuration rows yet; saved values, if any, are embedded in the section entry and metadata remains here.',
                        'example' => [
                            'alarm_clock' => [
                                'value' => [],
                                '_meta' => ['limit' => 3],
                            ],
                            'phonebook' => [
                                'value' => [],
                                '_meta' => ['limit' => 5],
                            ],
                            'sos_contacts' => [
                                'value' => [],
                                '_meta' => ['limit' => 3],
                            ],
                            'call_whitelist' => [
                                'value' => [],
                                '_meta' => ['limit' => 10],
                            ],
                            'whitelist_enabled' => [
                                'value' => ['enabled' => true],
                                '_meta' => [],
                            ],
                            'fall_sensitivity' => [
                                'value' => ['sensitivity' => 2, 'levels' => 8],
                                '_meta' => [
                                    'sensitivity' => ['options' => [
                                        ['value' => 1, 'label' => 'Baixa'],
                                        ['value' => 2, 'label' => 'Normal'],
                                        ['value' => 3, 'label' => 'Alta'],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                    'DeviceCapabilitiesMatrix' => [
                        'type' => 'object',
                        'properties' => [
                            'telemetry' => ['$ref' => '#/components/schemas/DeviceTelemetryCapabilitiesSection'],
                            'health' => ['$ref' => '#/components/schemas/DeviceConfiguredCapabilitiesSection'],
                            'contacts' => ['$ref' => '#/components/schemas/DeviceConfiguredCapabilitiesSection'],
                            'alarms' => ['$ref' => '#/components/schemas/DeviceConfiguredCapabilitiesSection'],
                            'settings_system' => ['$ref' => '#/components/schemas/DeviceConfiguredCapabilitiesSection'],
                        ],
                    ],
                    'DeviceCreateRequest' => [
                        'type' => 'object',
                        'required' => ['imei', 'supplier', 'model'],
                        'properties' => [
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'model' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'company' => ['type' => 'string', 'example' => 'hitcare'],
                            'licenseId' => ['type' => 'integer', 'example' => 0],
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
                        'required' => ['supplier', 'model'],
                        'properties' => [
                            'imei' => ['type' => 'string', 'example' => '865028000000307'],
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'model' => ['type' => 'string', 'example' => 'L08 Pro'],
                            'company' => ['type' => 'string', 'example' => 'hitcare'],
                            'licenseId' => ['type' => 'integer', 'example' => 0],
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
                    'DeviceConfigurationsUpdateRequest' => [
                        'type' => 'object',
                        'required' => ['configurations'],
                        'properties' => [
                            'configurations' => [
                                'type' => 'object',
                                'description' => 'Map of generic capability keys to desired payloads. Empty arrays clear list values. For alarm_clock, send {items:[{time,enabled,recurrence,type?,label?,url?}]}; Wonlex supports label and url. For Wonlex, phonebook is {contacts:[{name,phone}]}, sos_contacts is a flat subset of phone numbers already present in phonebook, whitelist_enabled controls CallInLimitSwitch, and sos_sms_alert controls SOSSwitch. call_whitelist is reserved for Vivistar and 4P Touch.',
                                'properties' => [
                                    'alarm_clock' => ['$ref' => '#/components/schemas/AlarmClockConfiguration'],
                                    'phonebook' => ['$ref' => '#/components/schemas/PhonebookConfiguration'],
                                ],
                                'additionalProperties' => true,
                                'example' => [
                                    'auto_vitals_interval' => ['enabled' => true, 'intervalMinutes' => 120],
                                    'phonebook' => ['contacts' => [['name' => 'HAVICARE SUPORTE', 'phone' => '+351278710140']]],
                                    'alarm_clock' => [
                                        'items' => [
                                            [
                                                'time' => '08:33',
                                                'enabled' => true,
                                                'type' => 2,
                                                'recurrence' => ['kind' => 'daily'],
                                            ],
                                        ],
                                    ],
                                    'sos_contacts' => ['+351278710140'],
                                    'call_whitelist' => ['contacts' => [['name' => 'HAVICARE SUPORTE', 'phone' => '+351278710140']]],
                                    'whitelist_enabled' => ['enabled' => true],
                                    'working_mode' => ['mode' => 3],
                                ],
                            ],
                        ],
                    ],
                    'DeviceConfigurationsUpdateResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'results', 'configurations'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'ok'],
                            'results' => [
                                'type' => 'array',
                                'description' => 'Changed generic capabilities and their protocol-native delivery operations.',
                                'items' => ['$ref' => '#/components/schemas/DeviceConfigurationMutationResult'],
                            ],
                            'configurations' => [
                                'type' => 'object',
                                'description' => 'Desired generic configuration values after the update.',
                            ],
                            'effectiveConfigurations' => ['type' => 'object'],
                            'configurationSync' => ['$ref' => '#/components/schemas/ConfigurationSync'],
                        ],
                    ],
                    'ConfigurationSync' => [
                        'type' => 'object',
                        'required' => ['status', 'hasUnconfirmedChanges', 'pendingCount', 'failedCount', 'entries'],
                        'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['confirmed', 'pending', 'failed']],
                            'hasUnconfirmedChanges' => ['type' => 'boolean'],
                            'pendingCount' => ['type' => 'integer'],
                            'failedCount' => ['type' => 'integer'],
                            'entries' => [
                                'type' => 'object',
                                'description' => 'Lifecycle entries grouped by capability section and generic key.',
                            ],
                        ],
                    ],
                    'DeviceConfigurationMutationResult' => [
                        'type' => 'object',
                        'required' => ['key', 'operations'],
                        'properties' => [
                            'key' => [
                                'type' => 'string',
                                'description' => 'Public generic capability key.',
                                'example' => 'alarm_clock',
                            ],
                            'changeId' => ['type' => 'string'],
                            'desiredRevision' => ['type' => 'integer'],
                            'operations' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/DeviceConfigurationNativeOperation'],
                            ],
                        ],
                    ],
                    'DeviceConfigurationNativeOperation' => [
                        'type' => 'object',
                        'required' => ['nativeKey', 'command', 'deliveryStatus', 'lastCommandId'],
                        'properties' => [
                            'nativeKey' => [
                                'type' => 'string',
                                'description' => 'Protocol-native configuration identity used internally for this operation.',
                                'example' => 'reminders',
                            ],
                            'command' => ['type' => 'string', 'example' => 'BP85'],
                            'deliveryStatus' => ['type' => 'string', 'example' => 'waiting'],
                            'lastCommandId' => ['type' => 'string', 'example' => 'f0c8771964d6175a'],
                        ],
                    ],
                    'DeviceAssociation' => [
                        'type' => 'object',
                        'required' => ['company', 'licenseId'],
                        'properties' => [
                            'company' => ['type' => 'string', 'example' => 'hitcare'],
                            'licenseId' => ['type' => 'integer', 'example' => 1001],
                        ],
                    ],
                    'DeviceAssociationRequest' => [
                        'type' => 'object',
                        'required' => ['company', 'licenseId'],
                        'description' => 'Associates the device to an existing company and license. If the company exists but the license row does not, the hub creates the license automatically using the requested licenseId.',
                        'properties' => [
                            'company' => ['type' => 'string', 'example' => 'hitcare'],
                            'licenseId' => ['type' => 'integer', 'example' => 1001],
                        ],
                    ],
                    'DeviceAssociationResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'imei', 'association'],
                        'description' => 'Returns the updated association after the device is linked to the company and license.',
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'ok'],
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                            'association' => ['$ref' => '#/components/schemas/DeviceAssociation'],
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
                    'TelemetryRequest' => [
                        'type' => 'object',
                        'required' => ['feature'],
                        'properties' => [
                            'feature' => ['type' => 'string', 'example' => 'heart_rate'],
                        ],
                    ],
                    'TelemetryAction' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'example' => 'heart_rate'],
                            'feature' => ['type' => 'string', 'example' => 'heart_rate'],
                            'label' => ['type' => 'string', 'example' => 'Heart rate'],
                            'icon' => ['type' => 'string', 'example' => 'fa-heart-pulse'],
                        ],
                    ],
                    'TelemetryRequestResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'waiting'],
                            'feature' => ['type' => 'string', 'example' => 'heart_rate'],
                            'commands' => ['type' => 'array', 'items' => ['type' => 'object']],
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
                    'DashboardNotificationItem' => [
                        'type' => 'object',
                        'required' => ['id', 'type', 'imei', 'protocol', 'occurrenceCount', 'firstSeenAt', 'lastSeenAt'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'type' => ['type' => 'string', 'example' => 'device_not_authorized'],
                            'imei' => ['type' => 'string', 'example' => '861265062544868'],
                            'protocol' => ['type' => 'string', 'example' => 'vivistar-iw'],
                            'model' => ['type' => 'string', 'example' => 'VL16P'],
                            'ident' => ['type' => 'string', 'example' => ''],
                            'reason' => ['type' => 'string', 'example' => 'device_not_authorized'],
                            'occurrenceCount' => ['type' => 'integer', 'example' => 2],
                            'firstSeenAt' => ['type' => 'string', 'format' => 'date-time'],
                            'lastSeenAt' => ['type' => 'string', 'format' => 'date-time'],
                            'readAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        ],
                    ],
                    'DashboardNotificationListResponse' => [
                        'type' => 'object',
                        'required' => ['data', 'unreadCount'],
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DashboardNotificationItem']],
                            'unreadCount' => ['type' => 'integer', 'example' => 1],
                        ],
                    ],
                    'DashboardNotificationReadRequest' => [
                        'type' => 'object',
                        'required' => ['ids'],
                        'properties' => [
                            'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'example' => [1, 2]],
                        ],
                    ],
                    'DashboardNotificationReadResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'unreadCount'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'ok'],
                            'unreadCount' => ['type' => 'integer', 'example' => 0],
                        ],
                    ],
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
                    'ApiUserListResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ApiUserItem']],
                            'pagination' => ['$ref' => '#/components/schemas/CollectionPagination'],
                            'filters' => ['$ref' => '#/components/schemas/CollectionFilters'],
                        ],
                    ],
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
                            'internalModel' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'commercialName' => ['type' => 'string', 'example' => 'Wonlex HW20 Pro'],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'protocol' => ['type' => 'string', 'example' => 'wonlex-json'],
                            'image' => ['type' => 'string', 'example' => '/images/wonlex.png'],
                            'capabilities' => ['$ref' => '#/components/schemas/ModelCapabilitiesMatrix'],
                            'requestableCapabilities' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'example' => ['blood_pressure', 'blood_oxygen', 'location'],
                            ],
                            'requestableCapabilityKeys' => [
                                'type' => 'array',
                                'description' => 'Telemetry features that the supplier protocol supports requesting for this model family.',
                                'items' => ['type' => 'string'],
                                'example' => ['heart_rate', 'blood_pressure', 'blood_oxygen', 'location'],
                            ],
                        ],
                    ],
                    'ModelCapabilitySection' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'boolean'],
                        'example' => [
                            'heart_rate' => true,
                            'blood_pressure' => true,
                            'blood_oxygen' => false,
                        ],
                    ],
                    'ModelCapabilitiesMatrix' => [
                        'type' => 'object',
                        'properties' => [
                            'telemetry' => ['$ref' => '#/components/schemas/ModelCapabilitySection'],
                            'health' => ['$ref' => '#/components/schemas/ModelCapabilitySection'],
                            'contacts' => ['$ref' => '#/components/schemas/ModelCapabilitySection'],
                            'alarms' => ['$ref' => '#/components/schemas/ModelCapabilitySection'],
                            'settings_system' => ['$ref' => '#/components/schemas/ModelCapabilitySection'],
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
                    'DeviceTypeSupplierItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'name' => ['type' => 'string', 'example' => 'Wonlex'],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                        ],
                    ],
                    'DeviceTypeSupplierGroup' => [
                        'type' => 'object',
                        'properties' => [
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'suppliers' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DeviceTypeSupplierItem']],
                        ],
                    ],
                    'ModelTemplateResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'supplier_id' => ['type' => 'integer', 'example' => 1],
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'protocol' => ['type' => 'string', 'example' => 'wonlex-json'],
                            'enabledCapabilities' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['heart_rate', 'ecg', 'hrv']],
                            'requestableCapabilityKeys' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['heart_rate', 'blood_pressure', 'blood_oxygen']],
                            'capabilities' => ['$ref' => '#/components/schemas/ModelCapabilitiesMatrix'],
                        ],
                    ],
                    'DeviceTypeSuppliersModelsResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DeviceTypeSupplierGroup']],
                        ],
                    ],
                    'DeviceTypeSupplierModelSummaryItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'supplier_id' => ['type' => 'integer', 'example' => 1],
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'internalModel' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'commercialName' => ['type' => 'string', 'example' => 'Wonlex HW20 Pro'],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'protocol' => ['type' => 'string', 'example' => 'wonlex-json'],
                            'image' => ['type' => 'string', 'nullable' => true, 'example' => '/model-images/abc123.jpg'],
                        ],
                    ],
                    'DeviceTypeSupplierWithModelsItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'name' => ['type' => 'string', 'example' => 'Wonlex'],
                            'enabled' => ['type' => 'boolean', 'example' => true],
                            'models' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DeviceTypeSupplierModelSummaryItem']],
                        ],
                    ],
                    'DeviceTypeSupplierGroupWithModels' => [
                        'type' => 'object',
                        'properties' => [
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'suppliers' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DeviceTypeSupplierWithModelsItem']],
                        ],
                    ],
                    'DeviceTypeSuppliersModelsHierarchyResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DeviceTypeSupplierGroupWithModels']],
                        ],
                    ],
                    'ModelWriteRequest' => [
                        'type' => 'object',
                        'required' => ['supplier_id', 'internalModel', 'commercialName'],
                        'properties' => [
                            'supplier_id' => ['type' => 'integer', 'example' => 1],
                            'internalModel' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'commercialName' => ['type' => 'string', 'example' => 'Wonlex HW20 Pro'],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'protocol' => ['type' => 'string', 'example' => 'wonlex-json'],
                            'image' => ['type' => 'string', 'format' => 'binary'],
                            'capabilitiesConfigured' => ['type' => 'string', 'example' => '1'],
                            'capabilities[]' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['heart_rate', 'phonebook']],
                            'requestableCapabilitiesConfigured' => ['type' => 'string', 'example' => '1'],
                            'requestableCapabilities[]' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['heart_rate', 'blood_pressure']],
                        ],
                    ],
                    'CapabilityItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'section' => [
                                'type' => 'string',
                                'enum' => ['telemetry', 'health', 'contacts', 'alarms', 'settings_system'],
                                'example' => 'telemetry',
                            ],
                            'sectionLabel' => ['type' => 'string', 'example' => 'Telemetria'],
                            'key' => ['type' => 'string', 'example' => 'heart_rate'],
                            'label' => ['type' => 'string', 'example' => 'Heart rate telemetry'],
                            'sortOrder' => ['type' => 'integer', 'example' => 20],
                            'isTelemetry' => ['type' => 'boolean', 'example' => true],
                            'isConfigurable' => ['type' => 'boolean', 'example' => false],
                            'isRequestable' => ['type' => 'boolean', 'example' => true],
                            'isEvent' => ['type' => 'boolean', 'example' => false],
                        ],
                    ],
                    'CapabilityListResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CapabilityItem']],
                        ],
                    ],
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
                    'CompanyListResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CompanyItem']],
                            'pagination' => ['$ref' => '#/components/schemas/CollectionPagination'],
                            'filters' => ['$ref' => '#/components/schemas/CollectionFilters'],
                        ],
                    ],
                    'CompanyWriteRequest' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string', 'example' => 'hitcare'],
                        ],
                    ],
                    'CompanyCreateResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'id'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'ok'],
                            'id' => ['type' => 'integer', 'example' => 1],
                        ],
                    ],
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
                    'LicenseListResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/LicenseItem']],
                            'pagination' => ['$ref' => '#/components/schemas/CollectionPagination'],
                            'filters' => ['$ref' => '#/components/schemas/CollectionFilters'],
                        ],
                    ],
                    'LicenseWriteRequest' => [
                        'type' => 'object',
                        'required' => ['companyId', 'licenseId'],
                        'properties' => [
                            'companyId' => ['type' => 'integer', 'example' => 1],
                            'licenseId' => ['type' => 'integer', 'example' => 1001],
                            'name' => ['type' => 'string', 'example' => 'gucc.dev'],
                        ],
                    ],
                    'LicenseCreateResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'id'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'ok'],
                            'id' => ['type' => 'integer', 'example' => 1],
                        ],
                    ],
                    'ProtocolConfigCatalogEntry' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'example' => 'fallDetection'],
                            'command' => ['type' => 'string', 'example' => 'BP76'],
                            'label' => ['type' => 'string', 'example' => 'Deteção de queda'],
                            'kind' => ['type' => 'string', 'example' => 'config'],
                            'risk' => ['type' => 'string', 'example' => 'normal'],
                            'input' => ['type' => 'string', 'example' => 'toggle'],
                            'fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['enabled']],
                            'expectedReplyTypes' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['AP76']],
                            'category' => ['type' => 'string', 'example' => 'alerts'],
                            'order' => ['type' => 'integer', 'example' => 10],
                            'limit' => ['type' => 'integer', 'nullable' => true, 'example' => 3],
                            'options' => ['type' => 'object', 'nullable' => true, 'example' => ['sensitivity' => [['value' => 1, 'label' => 'Baixa']]]],
                            'capabilityKey' => ['type' => 'string', 'nullable' => true, 'example' => 'fall_detection'],
                        ],
                    ],
                    'ProtocolItem' => [
                        'type' => 'object',
                        'properties' => [
                            'protocol' => ['type' => 'string', 'example' => 'four-p-touch'],
                            'label' => ['type' => 'string', 'example' => '4P Touch'],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                            'supportsConfigCatalog' => ['type' => 'boolean'],
                            'dashboard' => ['type' => 'object'],
                        ],
                    ],
                    'ProtocolConfigCatalogResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ProtocolConfigCatalogEntry']],
                        ],
                    ],
                    'ProtocolListResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ProtocolItem']],
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
