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
     * Company names are part of the MQTT topic, and topics are case sensitive:
     * "hitCare" and "hitcare" are two different tenants to a subscriber. One
     * casing, chosen here, keeps a tenant's devices in one place.
     */
    public static function normalizeCompany(?string $company): string
    {
        $normalized = strtolower(trim((string)$company));

        return $normalized !== '' ? $normalized : 'null';
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
