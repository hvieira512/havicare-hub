<?php

namespace Hub\Domain;

use Hub\Domain\Capability\CapabilityCatalog;

/**
 * @deprecated Usar o `CapabilityCatalog`. Fica como fachada compatível enquanto os chamadores
 * fora do domínio migram para o registo autoritativo.
 */
final class GenericModelCapabilityCatalog
{
    public static function deviceTypes(): array
    {
        return CapabilityCatalog::deviceTypes();
    }

    public static function sections(): array
    {
        return CapabilityCatalog::sections();
    }

    public static function definitions(): array
    {
        return CapabilityCatalog::definitions();
    }

    public static function keys(): array
    {
        return CapabilityCatalog::keys();
    }

    public static function keysForDeviceType(string $deviceType): array
    {
        return CapabilityCatalog::keysForDeviceType($deviceType);
    }

    public static function definitionsForDeviceType(string $deviceType): array
    {
        return CapabilityCatalog::definitionsForDeviceType($deviceType);
    }

    public static function keysForProtocol(string $protocol): array
    {
        return CapabilityCatalog::keysForProtocol($protocol);
    }

    public static function protocolSpecificKeys(string $protocol): array
    {
        return CapabilityCatalog::protocolSpecificKeys($protocol);
    }

    public static function telemetryKeysForProtocol(string $protocol): array
    {
        return CapabilityCatalog::telemetryKeysForProtocol($protocol);
    }

    public static function buildCapabilityMatrix(array $catalogRows, array $supportedKeys): array
    {
        return CapabilityCatalog::buildCapabilityMatrix($catalogRows, $supportedKeys);
    }

    public static function normalizeStoredCapabilityKey(string $key): ?string
    {
        return CapabilityCatalog::normalizeStoredCapabilityKey($key);
    }

    public static function sectionForCapabilityKey(string $key): ?string
    {
        return CapabilityCatalog::sectionForCapabilityKey($key);
    }

    public static function mapTelemetryFeature(string $feature): ?string
    {
        return CapabilityCatalog::mapTelemetryFeature($feature);
    }

    public static function mapConfigurationKey(string $key): ?string
    {
        return CapabilityCatalog::mapConfigurationKey($key);
    }
}
