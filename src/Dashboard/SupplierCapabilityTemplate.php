<?php

namespace Hub\Dashboard;

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

        $available = array_flip(GenericModelCapabilityCatalog::keysForDeviceType($deviceType));
        $supported = [];
        foreach (GenericModelCapabilityCatalog::keysForProtocol($protocol) as $key) {
            if (!isset($available[$key])) {
                continue;
            }
            $supported[$key] = true;
        }

        return array_keys($supported);
    }
}
