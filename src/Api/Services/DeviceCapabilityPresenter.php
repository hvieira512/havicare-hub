<?php

namespace Hub\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Command\DeviceCommandCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityHelpers;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\DeviceMetadata;

/**
 * Projecta a configuração guardada de um modelo na forma de capacidades que a API serve.
 *
 * Vive à parte do `DeviceService` porque só precisa do registo de capacidades e da base de
 * dados: nada do hub, da whitelist, do store ou da fila de downlink em torno dos quais o
 * resto dessa classe está construído.
 */
final class DeviceCapabilityPresenter
{
    use CapabilityHelpers;

    public function __construct(
        private CapabilityRegistry $capabilityRegistry,
        private ApiDataAccess $db,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledRequestCommandsForModel(?array $model, string $protocol): array
    {
        $commands = array_values(array_filter(
            DeviceCommandCatalog::commandsForProtocol($protocol),
            static fn(array $entry): bool => (string)($entry['kind'] ?? '') === 'request'
        ));

        if ($model === null) {
            return [];
        }

        $enabled = array_flip($this->db->modelCapabilities->requestableFeaturesForModelId((int)$model['id']));

        return array_values(array_filter($commands, static function (array $entry) use ($enabled): bool {
            $feature = (string)($entry['feature'] ?? '');
            return $feature !== '' && isset($enabled[$feature]);
        }));
    }

    /**
     * @param list<array<string, mixed>> $configRows
     * @return array<string, array<string, mixed>>
     */
    public function deviceCapabilities(?array $model, string $protocol, array $configRows): array
    {
        return $this->deviceCapabilitiesFromPayloadKey($model, $protocol, $configRows, 'desired_payload');
    }

    /**
     * @param list<array<string, mixed>> $configRows
     * @return array<string, array<string, mixed>>
     */
    public function deviceCapabilitiesFromPayloadKey(
        ?array $model,
        string $protocol,
        array $configRows,
        string $payloadKey,
        bool $includeDefaults = true,
    ): array {
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($model['device_type'] ?? 'watch'));
        $matrix = $this->supportedCapabilityMatrix($model, $protocol, $deviceType);

        $capabilities = [];
        foreach (CapabilityCatalog::sections() as $section => $_label) {
            $capabilities[$section] = [];
        }
        $capabilities['telemetry'] = $this->telemetryCapabilities($model, $protocol, $matrix);

        if ($includeDefaults) {
            $capabilities = $this->seedSupportedDefaults($capabilities, $matrix, $protocol);
        }

        [$capabilities, $stored] = $this->applyStoredRows($capabilities, $configRows, $payloadKey, $protocol);
        $capabilities = $this->wrapStoredEntries($capabilities, $stored, $protocol);
        $meta = $this->withContractFallbackMeta($stored['meta'], $capabilities, $protocol);
        $capabilities = $this->wrapRemainingEntries($capabilities, $meta, $stored['nativeKeyForGeneric'], $protocol);

        if ($deviceType === 'ncs') {
            $capabilities = $this->markSupportedWithoutConfiguration($capabilities, $matrix);
        }

        return $capabilities;
    }

    /**
     * O que o modelo declara suportar, ou o que o protocolo suporta quando o dispositivo não
     * tem modelo registado.
     *
     * @return array<string, array<string, bool>>
     */
    private function supportedCapabilityMatrix(?array $model, string $protocol, string $deviceType): array
    {
        $catalog = $this->db->genericCapabilities->all($deviceType);
        $supportedKeys = $model !== null
            ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($model['id'] ?? 0))
            : CapabilityCatalog::keysForProtocol($protocol);

        return CapabilityCatalog::buildCapabilityMatrix($catalog, $supportedKeys);
    }

    /**
     * As capacidades suportadas são servidas mesmo quando o dispositivo nunca guardou valor
     * nenhum, para a dashboard as poder oferecer.
     *
     * @param array<string, array<string, mixed>> $capabilities
     * @param array<string, array<string, bool>> $matrix
     * @return array<string, array<string, mixed>>
     */
    private function seedSupportedDefaults(array $capabilities, array $matrix, string $protocol): array
    {
        foreach ($matrix as $section => $sectionMatrix) {
            if ($section === 'telemetry') {
                continue;
            }

            foreach ($sectionMatrix as $genericKey => $supported) {
                if (!$supported || !$this->contractAllowsProtocol($genericKey, $protocol)) {
                    continue;
                }
                if (array_key_exists($genericKey, $capabilities[$section])) {
                    continue;
                }

                $entry = $this->defaultCapabilityEntry($protocol, $genericKey);
                if ($entry === []) {
                    continue;
                }
                $capabilities[$section][$genericKey] = $entry;
            }
        }

        return $capabilities;
    }

    /**
     * Dobra as linhas de configuração guardadas na árvore de capacidades, juntando os
     * metadados e as chaves nativas de que cada chave genérica foi construída.
     *
     * @param array<string, array<string, mixed>> $capabilities
     * @param list<array<string, mixed>> $configRows
     * @return array{0: array<string, array<string, mixed>>, 1: array{meta: array<string, mixed>, nativeKeyForGeneric: array<string, string>, nativeKeysPerGeneric: array<string, array<string, true>>}}
     */
    private function applyStoredRows(array $capabilities, array $configRows, string $payloadKey, string $protocol): array
    {
        $meta = [];
        $nativeKeysPerGeneric = [];
        $nativeKeyForGeneric = [];
        $storedGenericKeys = [];

        foreach ($configRows as $row) {
            $nativeKey = $this->storedNativeConfigurationKey($row);
            $payload = is_array($row[$payloadKey] ?? null) ? $row[$payloadKey] : [];
            if ($nativeKey === null || $payload === []) {
                continue;
            }

            $genericKey = CapabilityCatalog::normalizeStoredCapabilityKey(
                (string)($row['config_key'] ?? $nativeKey)
            );
            if ($genericKey === null) {
                continue;
            }

            $section = CapabilityCatalog::sectionForCapabilityKey($genericKey);
            if ($section === null || $section === 'telemetry') {
                continue;
            }

            if (!$this->contractAllowsProtocol($genericKey, $protocol)) {
                continue;
            }

            $normalized = $this->normalizeCapabilityValue($protocol, $genericKey, $nativeKey, $payload);
            if ($normalized === null) {
                continue;
            }

            if ($this->capabilityRegistry->has($genericKey) && $this->capabilityRegistry->get($genericKey)?->isList()) {
                $capabilities[$section][$genericKey] = $normalized;
            } else {
                $capabilities[$section][$genericKey] = $this->mergeCapabilityValue(
                    $genericKey,
                    isset($storedGenericKeys[$genericKey])
                        ? ($capabilities[$section][$genericKey] ?? null)
                        : null,
                    $normalized
                );
            }

            $storedGenericKeys[$genericKey] = true;
            $nativeKeyForGeneric[$genericKey] = $nativeKey;
            $nativeKeysPerGeneric[$genericKey][$nativeKey] = true;

            $entry = DeviceConfigurationCatalog::configForProtocol($protocol, $nativeKey);
            if ($entry === null) {
                continue;
            }

            if (isset($entry['options'])) {
                foreach ($entry['options'] as $field => $options) {
                    $meta[$genericKey][$field] = ['options' => $options];
                }
            }

            if (isset($entry['limit'])) {
                $existing = $meta[$genericKey]['limit'] ?? 0;
                $meta[$genericKey]['limit'] = max($existing, (int)$entry['limit']);
            }
        }

        return [$capabilities, [
            'meta' => $meta,
            'nativeKeyForGeneric' => $nativeKeyForGeneric,
            'nativeKeysPerGeneric' => $nativeKeysPerGeneric,
        ]];
    }

    /**
     * Embrulha as capacidades que vieram de linhas guardadas na sua forma pública
     * `{value, _meta}`.
     *
     * @param array<string, array<string, mixed>> $capabilities
     * @param array{meta: array<string, mixed>, nativeKeyForGeneric: array<string, string>, nativeKeysPerGeneric: array<string, array<string, true>>} $stored
     * @return array<string, array<string, mixed>>
     */
    private function wrapStoredEntries(array $capabilities, array $stored, string $protocol): array
    {
        foreach ($stored['meta'] as $genericKey => $metaData) {
            $hasContract = $this->capabilityRegistry->has($genericKey);
            if (!$this->contractAllowsProtocol($genericKey, $protocol)) {
                continue;
            }
            $supportsMultiple = $hasContract && $this->capabilityRegistry->get($genericKey)?->supportsMultipleNativeKeys();

            // Várias chaves nativas dobradas numa chave genérica sem contrato que diga como
            // as juntar: o valor cru fica como está.
            if (!$supportsMultiple && count($stored['nativeKeysPerGeneric'][$genericKey] ?? []) > 1) {
                continue;
            }

            foreach ($capabilities as $section => $sectionCaps) {
                if (!array_key_exists($genericKey, $sectionCaps)) {
                    continue;
                }

                $capabilities[$section][$genericKey] = $hasContract
                    ? $this->capabilityRegistry->responseEntry(
                        $protocol,
                        $genericKey,
                        (string)($stored['nativeKeyForGeneric'][$genericKey] ?? ''),
                        $sectionCaps[$genericKey],
                        $metaData,
                    )
                    : [
                        'value' => $sectionCaps[$genericKey],
                        '_meta' => $this->enrichCapabilityMeta($genericKey, $protocol, $metaData),
                    ];
                break;
            }
        }

        return $capabilities;
    }

    /**
     * Uma capacidade com contrato mas sem metadados guardados anuncia os metadados do
     * próprio contrato.
     *
     * @param array<string, mixed> $meta
     * @param array<string, array<string, mixed>> $capabilities
     * @return array<string, mixed>
     */
    private function withContractFallbackMeta(array $meta, array $capabilities, string $protocol): array
    {
        foreach ($capabilities as $sectionCaps) {
            foreach ($sectionCaps as $genericKey => $value) {
                if (isset($meta[$genericKey]) || !is_array($value) || !$this->capabilityRegistry->has($genericKey)) {
                    continue;
                }

                $fallbackMeta = $this->capabilityRegistry->get($genericKey)?->meta($protocol, []);
                if ($fallbackMeta !== []) {
                    $meta[$genericKey] = $fallbackMeta;
                }
            }
        }

        return $meta;
    }

    /**
     * Embrulha o que ainda é um valor nu, que é tudo o que veio dos valores por omissão e
     * não de uma linha guardada.
     *
     * @param array<string, array<string, mixed>> $capabilities
     * @param array<string, mixed> $meta
     * @param array<string, string> $nativeKeyForGeneric
     * @return array<string, array<string, mixed>>
     */
    private function wrapRemainingEntries(array $capabilities, array $meta, array $nativeKeyForGeneric, string $protocol): array
    {
        foreach ($capabilities as $section => $sectionCaps) {
            if ($section === 'telemetry') {
                continue;
            }

            foreach ($sectionCaps as $genericKey => $value) {
                if (
                    !is_array($value)
                    || (array_key_exists('value', $value) && array_key_exists('_meta', $value))
                ) {
                    continue;
                }

                if (!$this->capabilityRegistry->has($genericKey)) {
                    $capabilities[$section][$genericKey] = [
                        'value' => $value,
                        '_meta' => $this->enrichCapabilityMeta($genericKey, $protocol, $meta[$genericKey] ?? []),
                    ];
                    continue;
                }

                if (!$this->capabilityRegistry->supportsProtocol($genericKey, $protocol)) {
                    continue;
                }

                $capabilities[$section][$genericKey] = $this->capabilityRegistry->responseEntry(
                    $protocol,
                    $genericKey,
                    (string)($nativeKeyForGeneric[$genericKey] ?? ''),
                    $value,
                    $meta[$genericKey] ?? [],
                );
            }
        }

        return $capabilities;
    }

    /**
     * Os dispositivos NCS expõem capacidades que o hub não consegue configurar, e por isso
     * uma capacidade suportada sem entrada de configuração continua a ser anunciada.
     *
     * @param array<string, array<string, mixed>> $capabilities
     * @param array<string, array<string, bool>> $matrix
     * @return array<string, array<string, mixed>>
     */
    private function markSupportedWithoutConfiguration(array $capabilities, array $matrix): array
    {
        foreach ($matrix as $section => $sectionMatrix) {
            if ($section === 'telemetry') {
                continue;
            }

            foreach ($sectionMatrix as $genericKey => $supported) {
                if (!$supported || array_key_exists($genericKey, $capabilities[$section] ?? [])) {
                    continue;
                }

                $capabilities[$section][$genericKey] = ['supported' => true];
            }
        }

        return $capabilities;
    }

    /**
     * Uma capacidade com contrato só é servida para os protocolos que o contrato dela
     * suporta; uma sem contrato passa sempre.
     */
    private function contractAllowsProtocol(string $genericKey, string $protocol): bool
    {
        return !$this->capabilityRegistry->has($genericKey)
            || $this->capabilityRegistry->supportsProtocol($genericKey, $protocol);
    }

    private function defaultCapabilityEntry(string $protocol, string $genericKey): array
    {
        $entry = $this->configurationEntryForGenericKey($protocol, $genericKey);
        if ($entry === null) {
            return [];
        }

        $nativeKey = (string)($entry['key'] ?? '');
        if ($nativeKey === '') {
            return [];
        }

        $desired = $this->capabilityRegistry->defaultValue($protocol, $genericKey);
        if ($desired === [] && !$this->capabilityRegistry->has($genericKey)) {
            $desired = ConfigurationInputDefaults::forEntry($entry);
        }

        $value = $this->capabilityRegistry->has($genericKey)
            ? $desired
            : $this->normalizeCapabilityValue($protocol, $genericKey, $nativeKey, $desired);
        $meta = $this->defaultCapabilityMetaForEntry($genericKey, $protocol, $entry);

        if ($this->capabilityRegistry->has($genericKey)) {
            if (!$this->capabilityRegistry->supportsProtocol($genericKey, $protocol)) {
                return [];
            }
            return $this->capabilityRegistry->responseEntry($protocol, $genericKey, $nativeKey, $value, $meta);
        }

        $capability = [
            'value' => $value,
            '_meta' => $meta,
        ];
        return $capability;
    }

    private function configurationEntryForGenericKey(string $protocol, string $genericKey): ?array
    {
        foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
            if (CapabilityCatalog::mapConfigurationKey((string)($entry['key'] ?? '')) === $genericKey) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function defaultCapabilityMetaForEntry(string $genericKey, string $protocol, array $entry): array
    {
        $meta = [];
        if (isset($entry['options']) && is_array($entry['options'])) {
            foreach ($entry['options'] as $field => $options) {
                $meta[(string)$field] = ['options' => $options];
            }
        }
        if (isset($entry['limit'])) {
            $meta['limit'] = (int)$entry['limit'];
        }

        return $this->enrichCapabilityMeta($genericKey, $protocol, $meta);
    }

    /**
     * @param array<string, mixed>|null $matrix
     * @return array<string, array{supported: bool, requestable: bool}>
     */
    public function telemetryCapabilities(?array $model, string $protocol, ?array $matrix = null): array
    {
        if ($matrix === null) {
            $deviceType = DeviceMetadata::normalizeDeviceType((string)($model['device_type'] ?? 'watch'));
            $catalog = $this->db->genericCapabilities->all($deviceType);
            $supportedKeys = $model !== null
                ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($model['id'] ?? 0))
                : CapabilityCatalog::keysForProtocol($protocol);
            $matrix = CapabilityCatalog::buildCapabilityMatrix($catalog, $supportedKeys);
        }

        $requestable = [];
        foreach ($this->enabledRequestCommandsForModel($model, $protocol) as $entry) {
            $feature = trim((string)($entry['feature'] ?? ''));
            if ($feature !== '') {
                $requestable[$feature] = true;
            }
        }

        $telemetry = [];
        foreach ($matrix['telemetry'] ?? [] as $key => $supported) {
            if (!$supported) {
                continue;
            }
            $telemetry[$key] = [
                'supported' => true,
                'requestable' => isset($requestable[$key]),
            ];
        }

        return $telemetry;
    }

    public function normalizeCapabilityValue(
        string $protocol,
        string $genericKey,
        string $nativeKey,
        array $desired
    ): mixed {
        return $this->capabilityRegistry->fromNative($protocol, $genericKey, $nativeKey, $desired);
    }

    /**
     * @param array<string|int, mixed> $value
     * @return mixed
     */

    /**
     * @param array<string, mixed> $metaData
     * @return array<string, mixed>
     */
    private function enrichCapabilityMeta(string $genericKey, string $protocol, array $metaData): array
    {
        return $this->capabilityRegistry->get($genericKey)?->meta($protocol, $metaData) ?? $metaData;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function storedNativeConfigurationKey(array $row): ?string
    {
        $key = trim((string)($row['native_key'] ?? $row['config_key'] ?? ''));
        return $key !== '' ? $key : null;
    }

    private function mergeCapabilityValue(string $genericKey, mixed $existing, mixed $incoming): mixed
    {
        return $this->capabilityRegistry->merge($genericKey, $existing, $incoming);
    }
}
