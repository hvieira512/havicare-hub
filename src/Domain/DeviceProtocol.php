<?php

namespace Hub\Domain;

final class DeviceProtocol
{
    /**
     * Os fornecedores cujos modelos não partilham um protocolo só.
     *
     * A MOKO vende gateways e pulseiras, e por isso resolver só pelo fornecedor dava a uma
     * W6R o protocolo de gateway do MKGW3. As chaves vêm em minúsculas.
     *
     * @var array<string, array<string, string>>
     */
    private const PROTOCOL_BY_MODEL = [
        'moko' => [
            'mkgw3' => 'moko-mkgw3',
            'mkgw4' => 'moko-mkgw4',
            'w6r' => 'moko-w6r',
            'w6' => 'moko-w6',
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
