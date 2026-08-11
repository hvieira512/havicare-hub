<?php

namespace Hub\Domain;

final class DeviceMetadata
{
    public static function normalizeDeviceType(string $deviceType): string
    {
        $normalized = strtolower(trim($deviceType));

        return in_array($normalized, ['watch', 'ncs', 'radar', 'gateway', 'diaper_sensor', 'bracelet'], true) ? $normalized : 'watch';
    }

    /**
     * licenseId arrives as an int from the API and as a string from the
     * whitelist file and Redis. int is the canonical in-memory form -- it is
     * what tenant access control compares -- so every edge converges here.
     */
    public static function normalizeLicenseId(int|string $licenseId): int
    {
        $normalized = trim((string)$licenseId);

        return $normalized !== '' ? (int)$normalized : 0;
    }
}
