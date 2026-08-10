<?php

namespace Hub\Domain;

final class DeviceProtocol
{
    /**
     * Suppliers whose models do not share one protocol.
     *
     * MOKO ships gateways and a bracelet, so resolving by supplier alone would
     * hand a W6R the MKGW3 gateway protocol. Keys are lower-cased.
     *
     * @var array<string, array<string, string>>
     */
    private const PROTOCOL_BY_MODEL = [
        'moko' => [
            'mkgw3' => 'moko-mkgw3',
            'mkgw4' => 'moko-mkgw4',
            'w6r' => 'moko-w6r',
        ],
    ];

    public static function forSupplier(string $supplierName): string
    {
        return ProtocolRegistry::forSupplier($supplierName);
    }

    public static function forModel(string $supplierName, string $internalModel): string
    {
        $models = self::PROTOCOL_BY_MODEL[strtolower(trim($supplierName))] ?? null;
        $protocol = $models[strtolower(trim($internalModel))] ?? null;

        return $protocol ?? self::forSupplier($supplierName);
    }
}
