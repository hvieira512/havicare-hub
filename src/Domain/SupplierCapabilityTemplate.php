<?php

namespace Hub\Domain;

use Hub\Domain\Capability\CapabilityCatalog;

final class SupplierCapabilityTemplate
{
    /**
     * @return list<string>
     */
    public static function keysForSupplierDeviceType(string $supplierName, string $deviceType): array
    {
        $protocol = DeviceProtocol::forSupplier($supplierName);
        if ($protocol === '') {
            return [];
        }

        $available = array_flip(CapabilityCatalog::keysForDeviceType($deviceType));
        $supported = [];
        foreach (CapabilityCatalog::keysForProtocol($protocol) as $key) {
            if (!isset($available[$key])) {
                continue;
            }
            $supported[$key] = true;
        }

        return array_keys($supported);
    }
}
