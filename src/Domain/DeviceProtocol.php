<?php

declare(strict_types=1);

namespace Hub\Domain;

final class DeviceProtocol
{
    /**
     * Os fornecedores cujos modelos não partilham um protocolo, como a MOKO (gateways e pulseiras),
     * a Wonlex (relógios TCP e a MF91 BLE) e a JoyTech (tensiómetro e termómetro). Chaves em
     * minúsculas.
     *
     * @var array<string, array<string, string>>
     */
    private const PROTOCOL_BY_MODEL = [
        'moko' => [
            'mkgw3' => 'moko-mkgw3',
            'mkgw4' => 'moko-mkgw4',
            'w6b' => 'moko-w6b',
            'w6' => 'moko-w6',
        ],
        'wonlex' => [
            'mf91' => 'veepoo-ble',
        ],
        'joytech' => [
            'hc-3018b' => 'joytech-et',
            'hc-6679b' => 'joytech-bp-ecg',
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
