<?php

namespace Hub\Api\OpenApi\Schemas;

use Hub\Api\OpenApi\Responses;

/**
 * As descobertas de capacidades: o rascunho produzido a partir de um dispositivo ao vivo e o
 * model capability change it proposes.
 */
final class DiscoverySchemas
{
    public static function schemas(): array
    {
        return [
            'CapabilityDiscoveryRun' => [
                'type' => 'object',
                'required' => ['id', 'status', 'createdAt', 'device', 'model'],
                'properties' => [
                    'id' => ['type' => 'string', 'example' => 'disc_9f395f4f04fe589e'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'applied'], 'example' => 'draft'],
                    'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                    'appliedAt' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'nullable' => true,
                        'description' => 'Set once the draft has been applied to the model.',
                    ],
                    'device' => [
                        'type' => 'object',
                        'properties' => [
                            'imei' => ['type' => 'string', 'example' => '865028000000306'],
                            'online' => ['type' => 'boolean', 'example' => true],
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'model' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'commercialName' => ['type' => 'string', 'example' => 'Wonlex HW20 Pro'],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                        ],
                    ],
                    'model' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 1],
                            'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                            'internalModel' => ['type' => 'string', 'example' => 'HW20PRO'],
                            'commercialName' => ['type' => 'string', 'example' => 'Wonlex HW20 Pro'],
                            'deviceType' => ['type' => 'string', 'example' => 'watch'],
                        ],
                    ],
                    'currentEnabledCapabilityKeys' => [
                        'type' => 'array',
                        'description' => 'Capabilities currently enabled on the model.',
                        'items' => ['type' => 'string'],
                        'example' => ['heart_rate', 'location'],
                    ],
                    'suggestedEnabledCapabilityKeys' => [
                        'type' => 'array',
                        'description' => 'Capabilities the run proposes, taken from the device when it reports any and from the model otherwise.',
                        'items' => ['type' => 'string'],
                        'example' => ['heart_rate', 'location', 'blood_oxygen'],
                    ],
                    'changes' => [
                        'type' => 'object',
                        'properties' => [
                            'add' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['blood_oxygen']],
                            'remove' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => []],
                        ],
                    ],
                    'evidence' => [
                        'type' => 'array',
                        'items' => Responses::ref('CapabilityDiscoveryEvidence'),
                    ],
                ],
            ],
            'CapabilityDiscoveryEvidence' => [
                'type' => 'object',
                'description' => 'One catalog capability of the device type, with what the device supports and what the model currently declares.',
                'properties' => [
                    'section' => [
                        'type' => 'string',
                        'enum' => ['telemetry', 'health', 'contacts', 'alarms', 'settings_system'],
                        'example' => 'telemetry',
                    ],
                    'key' => ['type' => 'string', 'example' => 'heart_rate'],
                    'label' => ['type' => 'string', 'example' => 'Heart rate telemetry'],
                    'supported' => ['type' => 'boolean', 'example' => true],
                    'configured' => ['type' => 'boolean', 'example' => false],
                    'requestable' => ['type' => 'boolean', 'example' => true],
                    'telemetry' => ['type' => 'boolean', 'example' => true],
                ],
            ],
            'CapabilityDiscoveryListResponse' => CommonSchemas::list('CapabilityDiscoveryRun'),
        ];
    }
}
