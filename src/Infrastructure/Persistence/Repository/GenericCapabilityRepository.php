<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Repository;

use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\DeviceTypeCatalog;

/** O `CapabilityCatalog` nas linhas que a API lê; não há tabela por trás. */
final class GenericCapabilityRepository
{
    /** @return list<array<string, mixed>> */
    public function all(?string $deviceType = null): array
    {
        $definitions = $deviceType !== null && trim($deviceType) !== ''
            ? CapabilityCatalog::definitionsForDeviceType($deviceType)
            : CapabilityCatalog::definitions();

        $rows = array_map(static fn(array $definition): array => [
            'device_type' => (string)$definition['deviceType'],
            'section' => (string)$definition['section'],
            'capability_key' => (string)$definition['key'],
            'is_telemetry' => $definition['section'] === 'telemetry',
            'is_configurable' => (bool)($definition['isConfigurable'] ?? false),
            'is_requestable' => (bool)($definition['isRequestable'] ?? false),
            'is_event' => (bool)($definition['isEvent'] ?? false),
        ], $definitions);

        $deviceTypes = array_flip(DeviceTypeCatalog::keys());
        $sections = array_flip(CapabilityCatalog::sections());
        $order = static fn(array $row): array => [
            $deviceTypes[$row['device_type']] ?? PHP_INT_MAX,
            $sections[$row['section']] ?? PHP_INT_MAX,
            $row['capability_key'],
        ];
        usort($rows, static fn(array $left, array $right): int => $order($left) <=> $order($right));

        return $rows;
    }
}
