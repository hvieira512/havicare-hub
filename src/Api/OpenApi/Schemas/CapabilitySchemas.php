<?php

namespace Hub\Api\OpenApi\Schemas;

use Hub\Api\OpenApi\Responses;

/**
 * O catálogo de capacidades, a matriz por dispositivo, e os payloads das que se escrevem.
 */
final class CapabilitySchemas
{
    public static function schemas(): array
    {
        return array_merge(
            self::catalog(),
            self::deviceMatrix(),
            self::alarmClock(),
            self::phonebook(),
        );
    }

    private static function catalog(): array
    {
        return [
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
                    'isTelemetry' => ['type' => 'boolean', 'example' => true],
                    'isConfigurable' => ['type' => 'boolean', 'example' => false],
                    'isRequestable' => ['type' => 'boolean', 'example' => true],
                    'isEvent' => ['type' => 'boolean', 'example' => false],
                ],
            ],
            'CapabilityListResponse' => CommonSchemas::list('CapabilityItem'),
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
                    'options' => ['type' => 'array', 'items' => Responses::ref('CapabilityOption')],
                ],
            ],
        ];
    }

    private static function deviceMatrix(): array
    {
        $section = Responses::ref('DeviceConfiguredCapabilitiesSection');

        return [
            'DeviceTelemetryCapability' => [
                'type' => 'object',
                'properties' => [
                    'supported' => ['type' => 'boolean'],
                    'requestable' => ['type' => 'boolean'],
                ],
                'required' => ['supported', 'requestable'],
            ],
            'DeviceTelemetryCapabilitiesSection' => [
                'type' => 'object',
                'additionalProperties' => Responses::ref('DeviceTelemetryCapability'),
                'example' => [
                    'heart_rate' => ['supported' => true, 'requestable' => true],
                    'location' => ['supported' => true, 'requestable' => true],
                ],
            ],
            'DeviceConfiguredCapabilitiesSection' => [
                'type' => 'object',
                'properties' => [
                    'alarm_clock' => Responses::ref('AlarmClockCapability'),
                ],
                'additionalProperties' => true,
                'description' => 'Normalized writable capabilities for a section. Supported capabilities are present even when the device has no stored configuration rows yet; saved values, if any, are embedded in the section entry and metadata remains here. Only alarm_clock has a documented entry shape; the other capabilities vary by protocol.',
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
                    'telemetry' => Responses::ref('DeviceTelemetryCapabilitiesSection'),
                    'health' => $section,
                    'contacts' => $section,
                    'alarms' => $section,
                    'settings_system' => $section,
                ],
            ],
        ];
    }

    private static function alarmClock(): array
    {
        return [
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
                    'recurrence' => Responses::ref('AlarmClockRecurrence'),
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
                            'options' => ['type' => 'array', 'items' => Responses::ref('CapabilityOption')],
                        ],
                    ],
                    'days' => [
                        'type' => 'object',
                        'properties' => [
                            'options' => ['type' => 'array', 'items' => Responses::ref('CapabilityOption')],
                        ],
                    ],
                    'type' => [
                        'type' => 'object',
                        'properties' => [
                            'options' => ['type' => 'array', 'items' => Responses::ref('CapabilityOption')],
                        ],
                    ],
                    'label' => Responses::ref('CapabilityMetaField'),
                    'url' => Responses::ref('CapabilityMetaField'),
                ],
            ],
            'AlarmClockCapability' => [
                'type' => 'object',
                'description' => 'Public generic watch alarm clock capability exposed by GET /api/devices/{imei}. It is present whenever the model supports alarm_clock, even if no saved configuration exists yet. Vivistar exposes type metadata and requires type on PATCH; 4P Touch does not.',
                'properties' => [
                    'value' => [
                        'type' => 'array',
                        'items' => Responses::ref('AlarmClockItem'),
                    ],
                    '_meta' => Responses::ref('AlarmClockMeta'),
                ],
                'required' => ['value'],
            ],
            'AlarmClockConfiguration' => [
                'type' => 'object',
                'description' => 'Payload accepted by PATCH /api/devices/{imei}/configurations under configurations.alarm_clock. Send items as an array of alarms; an empty array is valid and clears the saved alarms. Include type only for Vivistar, and omit it for 4P Touch. Wonlex optionally supports label and an HTTP(S) audio URL, and supports only daily or custom recurrence because its protocol is a weekly Monday-to-Sunday mask.',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => Responses::ref('AlarmClockItem'),
                    ],
                ],
                'required' => ['items'],
            ],
        ];
    }

    private static function phonebook(): array
    {
        return [
            'PhonebookConfiguration' => [
                'type' => 'object',
                'description' => 'Payload accepted under configurations.phonebook for Wonlex and 4P Touch. Send only generic name and international phone fields; native IDs, area codes and SOS flags are managed by the hub. Wonlex accepts up to 10 contacts and stores the first 4 characters of each name; 4P Touch accepts up to 100 contacts and does not truncate names. The per-model limits are published in the phonebook capability metadata. An empty array clears the phonebook.',
                'properties' => [
                    'contacts' => [
                        'type' => 'array',
                        'maxItems' => 100,
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
                                    'description' => 'Unicode string. Truncated only where the capability metadata publishes a name.maxLength; a null maxLength means the device imposes none.',
                                ],
                            ],
                        ],
                    ],
                    'resync' => [
                        'type' => 'boolean',
                        'description' => '4P Touch repair. The hub cannot read the phonebook back from the watch, '
                            . 'so a contact written outside this API stays invisible to it and no removal reaches it. '
                            . 'With resync the hub stops trusting its own record: it clears every address on the device '
                            . 'and writes the list again. Expensive — one command per address — and meant for repair, '
                            . 'not for routine updates. Not stored: it describes this request, not the desired state.',
                    ],
                ],
                'required' => ['contacts'],
            ],
        ];
    }
}
