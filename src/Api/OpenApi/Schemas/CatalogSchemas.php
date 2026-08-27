<?php

namespace Hub\Api\OpenApi\Schemas;

use Hub\Api\OpenApi\Responses;

/**
 * Fornecedores, modelos, hierarquias de tipos de dispositivo e catálogos de protocolos.
 */
final class CatalogSchemas
{
    public static function schemas(): array
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
            'SupplierItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'Wonlex'],
                    'model_count' => ['type' => 'integer', 'example' => 2],
                    'created_at' => ['type' => 'string'],
                    'updated_at' => ['type' => 'string'],
                ],
            ],
            'SupplierListResponse' => CommonSchemas::collection('SupplierItem'),
        ];
    }

    private static function models(): array
    {
        $capabilitySection = Responses::ref('ModelCapabilitySection');

        return [
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
                    'capabilities' => Responses::ref('ModelCapabilitiesMatrix'),
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
            'ModelListResponse' => CommonSchemas::collection('ModelItem'),
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
                    'telemetry' => $capabilitySection,
                    'health' => $capabilitySection,
                    'contacts' => $capabilitySection,
                    'alarms' => $capabilitySection,
                    'settings_system' => $capabilitySection,
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
                    'capabilities' => Responses::ref('ModelCapabilitiesMatrix'),
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
        ];
    }

    private static function deviceTypes(): array
    {
        return [
            'DeviceTypeSupplierItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'Wonlex'],
                ],
            ],
            'DeviceTypeSupplierGroup' => [
                'type' => 'object',
                'properties' => [
                    'deviceType' => ['type' => 'string', 'example' => 'watch'],
                    'suppliers' => ['type' => 'array', 'items' => Responses::ref('DeviceTypeSupplierItem')],
                ],
            ],
            'DeviceTypeSuppliersModelsResponse' => CommonSchemas::list('DeviceTypeSupplierGroup'),
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
                    'models' => ['type' => 'array', 'items' => Responses::ref('DeviceTypeSupplierModelSummaryItem')],
                ],
            ],
            'DeviceTypeSupplierGroupWithModels' => [
                'type' => 'object',
                'properties' => [
                    'deviceType' => ['type' => 'string', 'example' => 'watch'],
                    'suppliers' => ['type' => 'array', 'items' => Responses::ref('DeviceTypeSupplierWithModelsItem')],
                ],
            ],
            'DeviceTypeSuppliersModelsHierarchyResponse' => CommonSchemas::list('DeviceTypeSupplierGroupWithModels'),
        ];
    }

    private static function protocols(): array
    {
        return [
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
            'ProtocolListResponse' => CommonSchemas::list('ProtocolItem'),
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
            'ProtocolConfigCatalogResponse' => CommonSchemas::list('ProtocolConfigCatalogEntry'),
        ];
    }
}
