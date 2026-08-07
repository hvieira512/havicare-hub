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
        return self::keysForModel($supplierName, '', $deviceType);
    }

    /** @return list<string> */
    public static function keysForModel(string $supplierName, string $internalModel, string $deviceType): array
    {
        $protocol = DeviceProtocol::forModel($supplierName, $internalModel);
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
