<?php

namespace Hub\Domain;

final class DeviceProtocol
{
    public static function forSupplier(string $supplierName): string
    {
        return ProtocolRegistry::forSupplier($supplierName);
    }
}
