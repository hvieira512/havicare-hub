<?php

namespace Hub\Domain;

use Hub\Domain\Capability\Contacts\WonlexContactCodec;

final class ProtocolRegistry
{
    /**
     * Os metadados canónicos de cada protocolo, usados pelos serviços e pela dashboard.
     *
     * @return array<string, array{
     *     label: string,
     *     deviceType: string,
     *     supportsConfigCatalog: bool,
     *     dashboard: array{
     *         groupedCapabilities: array<string, array{label: string, limit: int}>,
     *         fieldConstraints: array<string, array<string, mixed>>,
     *         helpCallPressModes?: list<string>
     *     }
     * }>
     */
    public static function all(): array
    {
        return [
            'wonlex-json' => [
                'label' => 'Wonlex',
                'deviceType' => 'watch',
                'supportsConfigCatalog' => true,
                'dashboard' => [
                    'groupedCapabilities' => [
                        'phonebook' => [
                            'label' => 'Lista telefónica',
                            'limit' => 10,
                        ],
                        'sos_contacts' => [
                            'label' => 'Contactos SOS',
                            'limit' => 10,
                        ],
                        'whitelist_enabled' => [
                            'label' => 'Restringir chamadas recebidas',
                            'limit' => 0,
                        ],
                    ],
                    'fieldConstraints' => [
                        'phonebook' => [
                            'name' => ['maxLength' => WonlexContactCodec::NAME_MAX_LENGTH],
                            'phone' => ['maxLength' => 20, 'asciiOnly' => true],
                            'allowPartialRows' => true,
                        ],
                    ],
                ],
            ],
            'vivistar-iw' => [
                'label' => 'Vivistar',
                'deviceType' => 'watch',
                'supportsConfigCatalog' => true,
                'dashboard' => [
                    'groupedCapabilities' => [],
                    'fieldConstraints' => [],
                ],
            ],
            'four-p-touch' => [
                'label' => '4P Touch',
                'deviceType' => 'watch',
                'supportsConfigCatalog' => true,
                'dashboard' => [
                    'groupedCapabilities' => [
                        'sos_contacts' => [
                            'label' => 'Contactos SOS',
                            'limit' => 3,
                        ],
                        'call_whitelist' => [
                            'label' => 'Lista branca',
                            'limit' => 10,
                        ],
                        'whitelist_enabled' => [
                            'label' => 'Lista branca ativa',
                            'limit' => 0,
                        ],
                    ],
                    'fieldConstraints' => [
                        'phonebook' => [
                            'name' => ['maxLength' => 10],
                            'phone' => ['maxLength' => 20, 'asciiOnly' => true],
                            'allowPartialRows' => true,
                        ],
                    ],
                ],
            ],
            'voerka-ncs' => [
                'label' => 'Voerka',
                'deviceType' => 'ncs',
                'supportsConfigCatalog' => false,
                'dashboard' => [
                    'groupedCapabilities' => [],
                    'fieldConstraints' => [],
                ],
            ],
            'qinglanst-radar' => [
                'label' => 'Qinglanst',
                'deviceType' => 'radar',
                'supportsConfigCatalog' => false,
                'dashboard' => [
                    'groupedCapabilities' => [],
                    'fieldConstraints' => [],
                ],
            ],
            'moko-mkgw3' => [
                'label' => 'MOKO',
                'deviceType' => 'gateway',
                'supportsConfigCatalog' => false,
                'dashboard' => ['groupedCapabilities' => [], 'fieldConstraints' => []],
            ],
            'moko-mkgw4' => [
                'label' => 'MOKO MKGW4',
                'deviceType' => 'gateway',
                'supportsConfigCatalog' => false,
                'dashboard' => ['groupedCapabilities' => [], 'fieldConstraints' => []],
            ],
            // `true` e não `false`: o sensor não aceita downlink, mas tem configurações, e
            // são coisas diferentes. Quem decide se algo viaja é cada capacidade, pelo
            // `HubAppliedCapability`, e não o protocolo inteiro.
            'monit-mecs-pro-ble' => [
                'label' => 'MONIT',
                'deviceType' => 'diaper_sensor',
                'supportsConfigCatalog' => true,
                'dashboard' => ['groupedCapabilities' => [], 'fieldConstraints' => []],
            ],
            'moko-w6b' => [
                'label' => 'MOKO W6B',
                'deviceType' => 'bracelet',
                'supportsConfigCatalog' => false,
                'dashboard' => [
                    'groupedCapabilities' => [],
                    'fieldConstraints' => [],
                    'helpCallPressModes' => ['single', 'double', 'long'],
                ],
            ],
            'moko-w6' => [
                'label' => 'MOKO W6',
                'deviceType' => 'bracelet',
                'supportsConfigCatalog' => false,
                // Não tem toque longo: o firmware BXP Nordic dá cliques simples, duplos e
                // triplos, e é por triplo que o longo da W6B é substituído.
                'dashboard' => [
                    'groupedCapabilities' => [],
                    'fieldConstraints' => [],
                    'helpCallPressModes' => ['single', 'double', 'triple'],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $protocol): bool
    {
        return isset(self::all()[trim($protocol)]);
    }

    public static function label(string $protocol): string
    {
        return (string)(self::all()[trim($protocol)]['label'] ?? trim($protocol));
    }

    /**
     * @return list<string>
     */
    public static function protocolsWithConfigCatalog(): array
    {
        return array_values(array_filter(
            self::keys(),
            static fn(string $protocol): bool => self::supportsConfigCatalog($protocol)
        ));
    }

    public static function supportsConfigCatalog(string $protocol): bool
    {
        return (bool)(self::all()[trim($protocol)]['supportsConfigCatalog'] ?? false);
    }

    /**
     * @return array{
     *     groupedCapabilities: array<string, array{label: string, limit: int}>,
     *     fieldConstraints: array<string, array<string, mixed>>,
     *     helpCallPressModes: list<string>
     * }
     */
    public static function dashboardMeta(string $protocol): array
    {
        $meta = self::all()[trim($protocol)]['dashboard'] ?? [];

        return [
            'groupedCapabilities' => is_array($meta['groupedCapabilities'] ?? null) ? $meta['groupedCapabilities'] : [],
            'fieldConstraints' => is_array($meta['fieldConstraints'] ?? null) ? $meta['fieldConstraints'] : [],
            'helpCallPressModes' => is_array($meta['helpCallPressModes'] ?? null) ? $meta['helpCallPressModes'] : [],
        ];
    }

    /**
     * @return array{protocol: string, label: string, deviceType: string, supportsConfigCatalog: bool, dashboard: array{groupedCapabilities: array<string, array{label: string, limit: int}>, fieldConstraints: array<string, array<string, mixed>>, helpCallPressModes: list<string>}}
     */
    public static function describe(string $protocol): array
    {
        $protocol = trim($protocol);
        $meta = self::all()[$protocol] ?? [
            'label' => $protocol,
            'deviceType' => 'watch',
            'supportsConfigCatalog' => false,
            'dashboard' => [
                'groupedCapabilities' => [],
                'fieldConstraints' => [],
            ],
        ];

        return [
            'protocol' => $protocol,
            'label' => (string)$meta['label'],
            'deviceType' => (string)$meta['deviceType'],
            'supportsConfigCatalog' => (bool)$meta['supportsConfigCatalog'],
            'dashboard' => self::dashboardMeta($protocol),
        ];
    }

    public static function forSupplier(string $supplierName): string
    {
        $supplierName = trim($supplierName);

        foreach (self::all() as $protocol => $meta) {
            if (($meta['label'] ?? '') === $supplierName) {
                return $protocol;
            }
        }

        return '';
    }
}
