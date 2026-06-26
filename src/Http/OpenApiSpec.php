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
            'tags' => [
                ['name' => 'Suppliers'],
                ['name' => 'Models'],
                ['name' => 'Companies'],
                ['name' => 'Licenses'],
                ['name' => 'Devices'],
                ['name' => 'API Users'],
                ['name' => 'System'],
            ],
            'paths' => [
                '/api/auth/login' => [
                    'post' => [
                        'tags' => ['System'],
                        'summary' => 'Issue bearer token for API access',
                        'requestBody' => [
                            'required' => true,
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'username' => ['type' => 'string'],
                                    'password' => ['type' => 'string'],
                                ],
                                'required' => ['username', 'password'],
                            ]]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Bearer token issued',
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
                                'description' => 'Device detail with configuration, capabilities and pending commands',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceDetailResponse']]],
                            ],
                            '400' => ['$ref' => '#/components/responses/Error'],
                            '403' => ['$ref' => '#/components/responses/Error'],
                            '404' => ['$ref' => '#/components/responses/Error'],
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
                                'description' => 'Device association updated',
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
                '/api/openapi.json' => [
                    'get' => [
                        'tags' => ['System'],
                        'summary' => 'OpenAPI specification',
                        'responses' => ['200' => ['description' => 'OpenAPI document']],
                    ],
                ],
                '/api/api-users' => [
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
                '/api/api-users/{id}' => [
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
                            'company' => ['type' => 'string', 'example' => 'hitCare'],
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
                            'supplier' => ['type' => 'string'],
                            'model' => ['type' => 'string'],
                            'deviceType' => ['type' => 'string'],
                            'company' => ['type' => 'string'],
                            'licenseId' => ['type' => 'string'],
                            'simNumber' => ['type' => 'string'],
                            'deviceId' => ['type' => 'string'],
                            'protocol' => ['type' => 'string', 'nullable' => true],
                            'transport' => ['type' => 'string', 'nullable' => true],
                            'lastConnectionId' => ['type' => 'string', 'nullable' => true],
                            'online' => ['type' => 'boolean'],
                            'lastSeenAt' => ['type' => 'string', 'nullable' => true],
                            'lastStateAt' => ['type' => 'string', 'nullable' => true],
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
                            'configuration' => ['$ref' => '#/components/schemas/DeviceConfigurationSummary'],
                            'capabilities' => ['$ref' => '#/components/schemas/DeviceCapabilitiesMatrix'],
                            'pending' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PendingCommand']],
                        ],
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
                        'additionalProperties' => ['type' => 'boolean'],
                        'example' => [
                            'heart_rate' => true,
                            'location' => true,
                        ],
                    ],
                    'DeviceConfiguredCapabilitiesSection' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'example' => [
                            'phonebook' => [
                                ['name' => 'Ana', 'phone' => '+351911111111'],
                            ],
                            'device_password' => ['password' => '2468'],
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
                            'company' => ['type' => 'string', 'example' => 'hitCare'],
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
                            'company' => ['type' => 'string', 'example' => 'hitCare'],
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
                    'DeviceAssociation' => [
                        'type' => 'object',
                        'required' => ['company', 'licenseId'],
                        'properties' => [
                            'company' => ['type' => 'string', 'example' => 'hitCare'],
                            'licenseId' => ['type' => 'string', 'example' => '1001'],
                        ],
                    ],
                    'DeviceAssociationRequest' => [
                        'type' => 'object',
                        'required' => ['company', 'licenseId'],
                        'properties' => [
                            'company' => ['type' => 'string', 'example' => 'hitCare'],
                            'licenseId' => ['type' => 'string', 'example' => '1001'],
                        ],
                    ],
                    'DeviceAssociationResponse' => [
                        'type' => 'object',
                        'required' => ['status', 'imei', 'association'],
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
                    'CommandRequest' => [
                        'type' => 'object',
                        'properties' => [
                            'command' => ['type' => 'string', 'example' => 'dnHeartRate'],
                            'requestId' => ['type' => 'string', 'example' => 'dnHeartRate'],
                            'feature' => ['type' => 'string', 'example' => 'heart_rate'],
                        ],
                    ],
                    'CommandResponse' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => 'sent'],
                            'command' => ['type' => 'object'],
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
                                    'role' => ['type' => 'string', 'enum' => ['hub_admin', 'license_client'], 'example' => 'license_client'],
                                    'license_id' => ['type' => 'string', 'nullable' => true, 'example' => '1001'],
                                    'expires_in' => ['type' => 'integer', 'example' => 3600],
                                    'expires_at' => ['type' => 'string', 'example' => '2026-06-23T12:00:00Z'],
                                ],
                            ],
                        ],
                    ],
                    'ApiUserItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'username' => ['type' => 'string', 'example' => 'tenant-1001'],
                            'role' => ['type' => 'string', 'enum' => ['hub_admin', 'license_client'], 'example' => 'license_client'],
                            'license_id' => ['type' => 'string', 'example' => '1001'],
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
                            'licenseId' => ['type' => 'string', 'description' => 'Required for license_client and ignored for hub_admin.', 'example' => '1001'],
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
                        ],
                    ],
                    'CompanyItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'name' => ['type' => 'string', 'example' => 'hitCare'],
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
                            'name' => ['type' => 'string', 'example' => 'hitCare'],
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
                            'company_name' => ['type' => 'string', 'example' => 'hitCare'],
                            'license_id' => ['type' => 'string', 'example' => '1001'],
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
                            'licenseId' => ['type' => 'string', 'example' => '1001'],
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
