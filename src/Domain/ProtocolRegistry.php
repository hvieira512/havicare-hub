<?php

declare(strict_types=1);

namespace Hub\Domain;

final class ProtocolRegistry
{
    /**
     * Os metadados canónicos de cada protocolo. O `supplier` é o nome do fornecedor quando não é
     * o `label`, os `models` os modelos internos que pedem este protocolo a um fornecedor com
     * vários, e os `helpCallPressModes` os toques que o botão de ajuda distingue.
     *
     * @return array<string, array{
     *     label: string,
     *     deviceType: string,
     *     supportsConfigCatalog: bool,
     *     supplier?: string,
     *     models?: list<string>,
     *     helpCallPressModes?: list<string>
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
            // As configurações vão à cloud do fabricante, e não ao aparelho.
            'qinglanst-radar' => [
                'label' => 'Qinglanst',
                'deviceType' => 'radar',
                'supportsConfigCatalog' => true,
            ],
            // A pulseira aceita downlink: a sessão GATT é bidirecional e o firmware confirma cada escrita,
            // ao contrário das W6/W6B.
            'veepoo-ble' => [
                'label' => 'Veepoo',
                'supplier' => 'Wonlex',
                'models' => ['mf91'],
                'deviceType' => 'bracelet',
                'supportsConfigCatalog' => true,
            ],
            'moko-mkgw3' => [
                'label' => 'MOKO',
                'deviceType' => 'gateway',
                'supportsConfigCatalog' => true,
            ],
            'moko-mkgw4' => [
                'label' => 'MOKO MKGW4',
                'supplier' => 'MOKO',
                'models' => ['mkgw4'],
                'deviceType' => 'gateway',
                'supportsConfigCatalog' => true,
            ],
            // `true`: o sensor não aceita downlink mas tem configurações, e quem decide se algo viaja é a
            // capacidade, pelo `CapabilityRegistry::travelsToDevice`.
            'monit-mecs-pro-ble' => [
                'label' => 'MONIT',
                'deviceType' => 'diaper_sensor',
                'supportsConfigCatalog' => true,
            ],
            'moko-w6b' => [
                'label' => 'MOKO W6B',
                'supplier' => 'MOKO',
                'models' => ['w6b'],
                'deviceType' => 'bracelet',
                'supportsConfigCatalog' => false,
                'helpCallPressModes' => ['single', 'double', 'long'],
            ],
            'moko-w6' => [
                'label' => 'MOKO W6',
                'supplier' => 'MOKO',
                'models' => ['w6'],
                'deviceType' => 'bracelet',
                'supportsConfigCatalog' => false,
                // O firmware BXP Nordic dá cliques simples, duplos e triplos, sem toque longo.
                'helpCallPressModes' => ['single', 'double', 'triple'],
            ],
            // A balança fala por GATT com um gateway por software, e não por anúncio.
            'lefu-torre' => [
                'label' => 'Unique Scales',
                'deviceType' => 'scale',
                'supportsConfigCatalog' => true,
            ],
            // O gateway BLE que a Havicare corre num computador, com as sessões GATT.
            'havicare-ble-gateway' => [
                'label' => 'Havicare',
                'deviceType' => 'gateway',
                'supportsConfigCatalog' => false,
            ],
            // Como a balança: GATT por um gateway por software, com o protocolo todo no hub.
            'joytech-bp' => [
                'label' => 'JoyTech',
                'models' => ['hc-6292b'],
                'deviceType' => 'blood_pressure_monitor',
                'supportsConfigCatalog' => true,
            ],
            'joytech-bp-ecg' => [
                'label' => 'JoyTech',
                'models' => ['hc-6679b'],
                'deviceType' => 'blood_pressure_monitor',
                'supportsConfigCatalog' => true,
            ],
            'joytech-et' => [
                'label' => 'JoyTech',
                'models' => ['hc-3018b'],
                'deviceType' => 'thermometer',
                'supportsConfigCatalog' => false,
            ],
            'joytech-spo2' => [
                'label' => 'JoyTech',
                'models' => ['hc-101'],
                'deviceType' => 'pulse_oximeter',
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
     * @return array{protocol: string, label: string, deviceType: string, supportsConfigCatalog: bool, helpCallPressModes: list<string>}
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
            'helpCallPressModes' => $meta['helpCallPressModes'] ?? [],
        ];
    }

    /** O protocolo de um modelo: o que o declara nos `models`, senão o do fornecedor. */
    public static function forModel(string $supplierName, string $internalModel): string
    {
        $model = strtolower(trim($internalModel));
        $entries = self::ofSupplier($supplierName);
        foreach ($entries as $protocol => $meta) {
            if (in_array($model, $meta['models'] ?? [], true)) {
                return $protocol;
            }
        }

        return self::defaultOf($entries);
    }

    /**
     * O protocolo de um fornecedor para um tipo de aparelho, sem modelo escolhido; um tipo que o
     * fornecedor não serve fica com o protocolo por omissão dele.
     */
    public static function forSupplier(string $supplierName, string $deviceType): string
    {
        $entries = self::ofSupplier($supplierName);
        $ofType = array_filter($entries, static fn(array $meta): bool => $meta['deviceType'] === trim($deviceType));

        return self::defaultOf($ofType !== [] ? $ofType : $entries);
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     */
    private static function defaultOf(array $entries): string
    {
        foreach ($entries as $protocol => $meta) {
            if (!isset($meta['models'])) {
                return $protocol;
            }
        }

        return (string)array_key_first($entries);
    }

    /** @return array<string, array<string, mixed>> */
    private static function ofSupplier(string $supplierName): array
    {
        $supplier = strtolower(trim($supplierName));

        return array_filter(
            self::all(),
            static fn(array $meta): bool => strtolower($meta['supplier'] ?? $meta['label']) === $supplier,
        );
    }
}
