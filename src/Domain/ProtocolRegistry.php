<?php

namespace Hub\Domain;

final class ProtocolRegistry
{
    /**
     * Os metadados canónicos de cada protocolo.
     *
     * O que a dashboard precisa para desenhar os campos -- etiquetas, comprimentos máximos
     * e modos de toque -- vive no `Hub\Api\Http\ProtocolDashboardMeta`, e não aqui: são
     * decisões sobre formulários, e esta camada não devia depender de quem os desenha. É o
     * `ProtocolService` que junta as duas coisas na resposta, que não muda de forma.
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
            // `true` e não `false`: o sensor não aceita downlink, mas tem configurações, e
            // são coisas diferentes. Quem decide se algo viaja é cada capacidade, pelo
            // `HubAppliedCapability`, e não o protocolo inteiro.
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
