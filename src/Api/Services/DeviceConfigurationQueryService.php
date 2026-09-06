<?php

namespace Hub\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityHelpers;
use Hub\Domain\Capability\CapabilityRegistry;

final class DeviceConfigurationQueryService
{
    use CapabilityHelpers;

    private PublicConfigurationValue $publicForm;

    public function __construct(
        private ApiDataAccess $db,
        private CapabilityRegistry $capabilities,
    ) {
        $this->publicForm = new PublicConfigurationValue();
    }

    /**
     * @param list<array<string, mixed>>|null $rows As linhas já lidas, para o detalhe não repetir
     *   a mesma consulta três vezes por pedido. Sem elas, lê-as.
     * @return array<string, mixed>
     */
    public function current(string $imei, string $protocol, ?array $rows = null): array
    {
        $configurations = [];
        foreach ($rows ?? $this->db->deviceConfigurations->allForImei($imei) as $row) {
            $desired = $row['desired_payload'];
            if (!is_array($desired) || $desired === []) {
                continue;
            }

            $nativeKey = $this->storedNativeKey($row);
            $genericKey = CapabilityCatalog::normalizeStoredCapabilityKey(
                (string)($row['config_key'] ?? $nativeKey ?? '')
            );
            if ($nativeKey === null || $genericKey === null) {
                continue;
            }

            $section = CapabilityCatalog::sectionForCapabilityKey($genericKey);
            if ($section === null || $section === 'telemetry') {
                continue;
            }

            $normalized = $this->publicValue(
                $protocol,
                $genericKey,
                $this->capabilities->fromNative($protocol, $genericKey, $nativeKey, $desired)
            );
            if ($normalized === null) {
                continue;
            }

            $supportsMultipleNativeKeys = $this->capabilities->has($genericKey)
                && $this->capabilities->get($genericKey)?->supportsMultipleNativeKeys();
            if (!array_key_exists($genericKey, $configurations) || !$supportsMultipleNativeKeys) {
                $configurations[$genericKey] = $normalized;
                continue;
            }

            $configurations[$genericKey] = $this->capabilities->merge(
                $genericKey,
                $configurations[$genericKey],
                $normalized
            );
        }

        return $configurations;
    }

    public function publicValue(string $protocol, string $genericKey, mixed $value): mixed
    {
        return $this->publicForm->forGenericKey($protocol, $genericKey, $value);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function storedNativeKey(array $row): ?string
    {
        $key = trim((string)($row['native_key'] ?? $row['config_key'] ?? ''));
        return $key === '' ? null : $key;
    }
}
