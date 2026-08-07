<?php

namespace Hub\Domain;

final class DeviceProtocol
{
    public static function forSupplier(string $supplierName): string
    {
        return ProtocolRegistry::forSupplier($supplierName);
    }

    public static function forModel(string $supplierName, string $internalModel): string
    {
        if (strcasecmp(trim($supplierName), 'MOKO') === 0 && strcasecmp(trim($internalModel), 'MKGW4') === 0) {
            return 'moko-mkgw4';
        }
        return self::forSupplier($supplierName);
    }
}
