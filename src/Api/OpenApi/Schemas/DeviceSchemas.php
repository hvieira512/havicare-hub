<?php

namespace Hub\Api\OpenApi\Schemas;

use Hub\Api\OpenApi\Responses;
use Hub\Api\OpenApi\SchemaFromRequest;
use Hub\Api\Request\DeviceAssociationRequest;
use Hub\Api\Request\DeviceWriteRequest;

/**
 * Os recursos de dispositivo: resumos, detalhe, ciclo de vida da configuração e telemetria.
 */
final class DeviceSchemas
{
    public static function schemas(): array
    {
        return array_merge(
            self::device(),
            self::links(),
            self::commands(),
            self::configurations(),
            self::association(),
            self::telemetry(),
        );
    }

    private static function device(): array
    {
        return [
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
            'DeviceListResponse' => CommonSchemas::collection('DeviceSummary'),
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
            'DeviceDetailResponse' => [
                'type' => 'object',
                'properties' => [
                    'device' => Responses::ref('DeviceDetail'),
                    'model' => Responses::ref('ModelDetail'),
                    'configuration' => Responses::ref('DeviceConfigurationSummary'),
                    'configurations' => [
                        'type' => 'object',
                        'description' => 'Desired generic configuration values stored by the Hub.',
                    ],
                    'effectiveConfigurations' => [
                        'type' => 'object',
                        'description' => 'Configuration values confirmed as effective by the device contract.',
                    ],
                    'configurationSync' => Responses::ref('ConfigurationSync'),
                    'capabilities' => Responses::ref('DeviceCapabilitiesMatrix'),
                ],
            ],
            // O `imei` é obrigatório a criar e herdado do endereço a actualizar, e é a única
            // diferença entre os dois: daí o grupo, e daí serem duas derivações do mesmo
            // objecto. Escritos à mão, nenhum dos dois mencionava o `deviceType`, que o
            // serviço lê desde sempre.
            'DeviceCreateRequest' => SchemaFromRequest::schema(
                DeviceWriteRequest::class,
                [DeviceWriteRequest::GROUP_CREATE],
            ),
            'DeviceCreateResponse' => [
                'type' => 'object',
                'required' => ['status', 'imei'],
                'properties' => [
                    'status' => ['type' => 'string', 'example' => 'ok'],
                    'imei' => ['type' => 'string', 'example' => '865028000000306'],
                ],
            ],
            'DeviceUpdateRequest' => SchemaFromRequest::schema(DeviceWriteRequest::class),
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
        ];
    }

    private static function links(): array
    {
        return [
            'DeviceLinkItem' => [
                'type' => 'object',
                'description' => 'A gateway-to-diaper-sensor link, joined with the whitelist metadata of the device on the other end of the link.',
                'properties' => [
                    'gatewayDeviceKey' => ['type' => 'string', 'example' => '865028000000306'],
                    'linkedDeviceKey' => ['type' => 'string', 'example' => 'eec5000202f9'],
                    'deviceKey' => [
                        'type' => 'string',
                        'description' => 'The other end of the link, relative to the device in the request path.',
                        'example' => 'eec5000202f9',
                    ],
                    'enabled' => ['type' => 'integer', 'example' => 1],
                    'supplier' => ['type' => 'string', 'example' => 'Wonlex'],
                    'model' => ['type' => 'string', 'example' => 'HW20PRO'],
                    'deviceType' => ['type' => 'string', 'example' => 'diaper_sensor'],
                    'licenseId' => ['type' => 'integer', 'example' => 1001],
                    'company' => ['type' => 'string', 'example' => 'hitcare'],
                    'created_at' => ['type' => 'string'],
                    'updated_at' => ['type' => 'string'],
                ],
            ],
            'DeviceLinkListResponse' => CommonSchemas::list('DeviceLinkItem'),
            'DeviceLinkMutationResponse' => [
                'type' => 'object',
                'required' => ['status', 'gatewayDeviceKey', 'linkedDeviceKey'],
                'properties' => [
                    'status' => ['type' => 'string', 'example' => 'ok'],
                    'gatewayDeviceKey' => ['type' => 'string', 'example' => '865028000000306'],
                    'linkedDeviceKey' => ['type' => 'string', 'example' => 'eec5000202f9'],
                ],
            ],
        ];
    }

    private static function commands(): array
    {
        return [
            'CommandStatusResponse' => [
                'type' => 'object',
                'required' => ['device', 'command'],
                'properties' => [
                    'device' => [
                        'type' => 'object',
                        'description' => 'Runtime snapshot of the device the command belongs to.',
                    ],
                    'command' => Responses::ref('CommandRecord'),
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
        ];
    }

    private static function configurations(): array
    {
        return [
            'DeviceConfigurationSummary' => [
                'type' => 'object',
                'properties' => [
                    'supported' => ['type' => 'integer', 'example' => 12],
                    'stored' => ['type' => 'integer', 'example' => 3],
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
                            'alarm_clock' => Responses::ref('AlarmClockConfiguration'),
                            'phonebook' => Responses::ref('PhonebookConfiguration'),
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
                        'items' => Responses::ref('DeviceConfigurationMutationResult'),
                    ],
                    'configurations' => [
                        'type' => 'object',
                        'description' => 'Desired generic configuration values after the update.',
                    ],
                    'effectiveConfigurations' => ['type' => 'object'],
                    'configurationSync' => Responses::ref('ConfigurationSync'),
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
                        'items' => Responses::ref('DeviceConfigurationNativeOperation'),
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
        ];
    }

    private static function association(): array
    {
        $association = [
            'company' => ['type' => 'string', 'example' => 'hitcare'],
            'licenseId' => ['type' => 'integer', 'example' => 1001],
        ];

        return [
            'DeviceAssociation' => [
                'type' => 'object',
                'required' => ['company', 'licenseId'],
                'properties' => $association,
            ],
            // Derivado do `DeviceAssociationRequest`. A descrição fica à mão porque descreve
            // o que a rota *faz* com o corpo -- criar a licença em falta --, e isso não é uma
            // regra sobre a forma dele.
            'DeviceAssociationRequest' => array_merge(
                SchemaFromRequest::schema(DeviceAssociationRequest::class),
                ['description' => 'Associates the device to an existing company and license. If the company exists but the license row does not, the hub creates the license automatically using the requested licenseId.'],
            ),
            'DeviceAssociationResponse' => [
                'type' => 'object',
                'required' => ['status', 'imei', 'association'],
                'description' => 'Returns the updated association after the device is linked to the company and license.',
                'properties' => [
                    'status' => ['type' => 'string', 'example' => 'ok'],
                    'imei' => ['type' => 'string', 'example' => '865028000000306'],
                    'association' => Responses::ref('DeviceAssociation'),
                ],
            ],
        ];
    }

    private static function telemetry(): array
    {
        return [
            'TelemetryRequest' => [
                'type' => 'object',
                'required' => ['feature'],
                'properties' => [
                    'feature' => ['type' => 'string', 'example' => 'heart_rate'],
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
        ];
    }
}
