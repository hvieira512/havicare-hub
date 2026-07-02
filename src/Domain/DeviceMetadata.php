<?php

namespace Hub\Domain;

final class DeviceMetadata
{
    public static function normalizeDeviceType(string $deviceType): string
    {
        $normalized = strtolower(trim($deviceType));

        return in_array($normalized, ['watch', 'ncs', 'radar'], true) ? $normalized : 'watch';
    }

    public static function normalizeLicenseId(string $licenseId): int
    {
        $normalized = trim($licenseId);

        return $normalized !== '' ? (int)$normalized : 0;
    }
}
