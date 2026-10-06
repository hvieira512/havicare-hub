<?php

declare(strict_types=1);

namespace Hub\Domain;

final class ProtocolRegistry
{
    /**
     * Os metadados canónicos de cada protocolo; o que a dashboard precisa vive no
     * `Api\Http\ProtocolDashboardMeta`.
     *
     * @return array<string, array{
     *     label: string,
     *     deviceType: string,
     *     supportsConfigCatalog: bool
     * }>
     */
    public static function all(): array
    {
        static $cache = null;

        return $cache ??= [
            'wonlex-json' => [
                'label' => 'Wonlex',
                'deviceType' => 'watch',
                'supportsConfigCatalog' => true,
            ],
            'vivistar-iw' => [
                'label' => 'Vivistar',
                'deviceType' => 'watch',
                'supportsConfigCatalog' => true,
            ],
            'four-p-touch' => [
                'label' => '4P Touch',
                'deviceType' => 'watch',
                'supportsConfigCatalog' => true,
            ],
            'voerka-ncs' => [
                'label' => 'Voerka',
                'deviceType' => 'ncs',
                'supportsConfigCatalog' => false,
            ],
            'qinglanst-radar' => [
                'label' => 'Qinglanst',
                'deviceType' => 'radar',
                'supportsConfigCatalog' => false,
            ],
            // A pulseira aceita downlink: a sessão GATT é bidirecional e o firmware confirma cada escrita,
            // ao contrário das W6/W6B.
            'veepoo-ble' => [
                'label' => 'Veepoo',
                'deviceType' => 'bracelet',
                'supportsConfigCatalog' => true,
            ],
            'moko-mkgw3' => [
                'label' => 'MOKO',
                'deviceType' => 'gateway',
                'supportsConfigCatalog' => false,
            ],
            'moko-mkgw4' => [
                'label' => 'MOKO MKGW4',
                'deviceType' => 'gateway',
                'supportsConfigCatalog' => false,
            ],
            // `true`: o sensor não aceita downlink mas tem configurações, e quem decide se algo viaja é a
            // capacidade, pelo `HubAppliedCapability`.
            'monit-mecs-pro-ble' => [
                'label' => 'MONIT',
                'deviceType' => 'diaper_sensor',
                'supportsConfigCatalog' => true,
            ],
            'moko-w6b' => [
                'label' => 'MOKO W6B',
                'deviceType' => 'bracelet',
                'supportsConfigCatalog' => false,
            ],
            'moko-w6' => [
                'label' => 'MOKO W6',
                'deviceType' => 'bracelet',
                'supportsConfigCatalog' => false,
            ],
            'zayata-m228' => [
                'label' => 'Zayata',
                'deviceType' => 'pill_dispenser',
                'supportsConfigCatalog' => true,
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

    /**
     * Os protocolos que servem um tipo de aparelho.
     *
     * @return list<string>
     */
    public static function protocolsForDeviceType(string $deviceType): array
    {
        $deviceType = trim($deviceType);

        return array_keys(array_filter(
            self::all(),
            static fn(array $meta): bool => $meta['deviceType'] === $deviceType,
        ));
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
     * @return array{protocol: string, label: string, deviceType: string, supportsConfigCatalog: bool}
     */
    public static function describe(string $protocol): array
    {
        $protocol = trim($protocol);
        $meta = self::all()[$protocol] ?? [
            'label' => $protocol,
            'deviceType' => 'watch',
            'supportsConfigCatalog' => false,
        ];

        return [
            'protocol' => $protocol,
            'label' => (string)$meta['label'],
            'deviceType' => (string)$meta['deviceType'],
            'supportsConfigCatalog' => (bool)$meta['supportsConfigCatalog'],
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
