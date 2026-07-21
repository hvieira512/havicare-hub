<?php

namespace Hub\Domain;

final class ProtocolRegistry
{
    /**
     * Canonical protocol metadata used by backend services and the dashboard.
     *
     * @return array<string, array{
     *     label: string,
     *     supplier: string,
     *     deviceType: string,
     *     supportsConfigCatalog: bool,
     *     dashboard: array{
     *         categoryLabels: array<string, string>,
     *         categoryOrder: list<string>,
     *         groupedCapabilities: array<string, array{label: string, limit: int}>,
     *         fieldConstraints: array<string, array<string, mixed>>
     *     }
     * }>
     */
    public static function all(): array
    {
        return [
            'wonlex-json' => [
                'label' => 'Wonlex',
                'supplier' => 'Wonlex',
                'deviceType' => 'watch',
                'supportsConfigCatalog' => true,
                'dashboard' => [
                    'categoryLabels' => [
                        'contacts' => 'Contactos',
                        'alerts' => 'Alarmes',
                        'health' => 'Saúde',
                        'measurements' => 'Medições',
                        'system' => 'Sistema',
                        'intervals' => 'Intervalos',
                    ],
                    'categoryOrder' => ['intervals', 'contacts', 'measurements', 'alerts', 'health', 'system'],
                    'groupedCapabilities' => [],
                    'fieldConstraints' => [],
                ],
            ],
            'vivistar-iw' => [
                'label' => 'Vivistar',
                'supplier' => 'Vivistar',
                'deviceType' => 'watch',
                'supportsConfigCatalog' => true,
                'dashboard' => [
                    'categoryLabels' => [
                        'contacts' => 'Contactos',
                        'alerts' => 'Alertas',
                        'health' => 'Saúde',
                        'system' => 'Sistema',
                        'intervals' => 'Intervalos',
                    ],
                    'categoryOrder' => ['contacts', 'alerts', 'health', 'system', 'intervals'],
                    'groupedCapabilities' => [],
                    'fieldConstraints' => [],
                ],
            ],
            'four-p-touch' => [
                'label' => '4P Touch',
                'supplier' => '4P Touch',
                'deviceType' => 'watch',
                'supportsConfigCatalog' => true,
                'dashboard' => [
                    'categoryLabels' => [
                        'contacts' => 'Contactos',
                        'alerts' => 'Alertas',
                        'health' => 'Saúde',
                        'system' => 'Sistema',
                        'intervals' => 'Intervalos',
                    ],
                    'categoryOrder' => ['intervals', 'contacts', 'alerts', 'health', 'system'],
                    'groupedCapabilities' => [
                        'sos_contacts' => [
                            'label' => 'Contactos SOS',
                            'limit' => 3,
                        ],
                        'call_whitelist' => [
                            'label' => 'Chamadas permitidas',
                            'limit' => 10,
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
                'supplier' => 'Voerka',
                'deviceType' => 'ncs',
                'supportsConfigCatalog' => false,
                'dashboard' => [
                    'categoryLabels' => [],
                    'categoryOrder' => [],
                    'groupedCapabilities' => [],
                    'fieldConstraints' => [],
                ],
            ],
            'qinglanst-radar' => [
                'label' => 'Qinglanst',
                'supplier' => 'Qinglanst',
                'deviceType' => 'radar',
                'supportsConfigCatalog' => false,
                'dashboard' => [
                    'categoryLabels' => [],
                    'categoryOrder' => [],
                    'groupedCapabilities' => [],
                    'fieldConstraints' => [],
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

    public static function supplierForProtocol(string $protocol): string
    {
        return (string)(self::all()[trim($protocol)]['supplier'] ?? '');
    }

    public static function deviceTypeForProtocol(string $protocol): string
    {
        return (string)(self::all()[trim($protocol)]['deviceType'] ?? 'watch');
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
     *     categoryLabels: array<string, string>,
     *     categoryOrder: list<string>,
     *     groupedCapabilities: array<string, array{label: string, limit: int}>,
     *     fieldConstraints: array<string, array<string, mixed>>
     * }
     */
    public static function dashboardMeta(string $protocol): array
    {
        $meta = self::all()[trim($protocol)]['dashboard'] ?? [];

        return [
            'categoryLabels' => is_array($meta['categoryLabels'] ?? null) ? $meta['categoryLabels'] : [],
            'categoryOrder' => array_values(is_array($meta['categoryOrder'] ?? null) ? $meta['categoryOrder'] : []),
            'groupedCapabilities' => is_array($meta['groupedCapabilities'] ?? null) ? $meta['groupedCapabilities'] : [],
            'fieldConstraints' => is_array($meta['fieldConstraints'] ?? null) ? $meta['fieldConstraints'] : [],
        ];
    }

    /**
     * @return array{protocol: string, label: string, supplier: string, deviceType: string, supportsConfigCatalog: bool, dashboard: array{categoryLabels: array<string, string>, categoryOrder: list<string>, groupedCapabilities: array<string, array{label: string, limit: int}>, fieldConstraints: array<string, array<string, mixed>>}}
     */
    public static function describe(string $protocol): array
    {
        $protocol = trim($protocol);
        $meta = self::all()[$protocol] ?? [
            'label' => $protocol,
            'supplier' => '',
            'deviceType' => 'watch',
            'supportsConfigCatalog' => false,
            'dashboard' => [
                'categoryLabels' => [],
                'categoryOrder' => [],
                'groupedCapabilities' => [],
                'fieldConstraints' => [],
            ],
        ];

        return [
            'protocol' => $protocol,
            'label' => (string)$meta['label'],
            'supplier' => (string)$meta['supplier'],
            'deviceType' => (string)$meta['deviceType'],
            'supportsConfigCatalog' => (bool)$meta['supportsConfigCatalog'],
            'dashboard' => self::dashboardMeta($protocol),
        ];
    }

    public static function forSupplier(string $supplierName): string
    {
        $supplierName = trim($supplierName);

        foreach (self::all() as $protocol => $meta) {
            if (($meta['supplier'] ?? '') === $supplierName) {
                return $protocol;
            }
        }

        return '';
    }
}
