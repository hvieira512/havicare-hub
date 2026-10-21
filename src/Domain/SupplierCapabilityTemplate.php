<?php

declare(strict_types=1);

namespace Hub\Domain;

use Hub\Domain\Capability\CapabilityCatalog;

final class SupplierCapabilityTemplate
{
    /** @return list<string> */
    public static function keysForSupplierDeviceType(string $supplierName, string $deviceType): array
    {
        return self::keys(ProtocolRegistry::forSupplier($supplierName, $deviceType), $deviceType);
    }

    /** @return list<string> */
    public static function keysForModel(string $supplierName, string $internalModel, string $deviceType): array
    {
        return self::keys(ProtocolRegistry::forModel($supplierName, $internalModel), $deviceType);
    }

    /** @return list<string> */
    private static function keys(string $protocol, string $deviceType): array
    {
        if ($protocol === '') {
            return [];
        }

        $available = array_flip(CapabilityCatalog::keysForDeviceType($deviceType));

        return array_values(array_filter(
            CapabilityCatalog::keysForProtocol($protocol),
            static fn(string $key): bool => isset($available[$key]),
        ));
    }
}
