<?php

namespace Hub\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Command\DeviceCommandCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityHelpers;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\DeviceMetadata;

/**
 * Projects a model's stored configuration into the capability shape the API
 * serves.
 *
 * Split out of DeviceService, where it was most of the file: it needs only the
 * capability registry and the database, and none of the hub, whitelist, store
 * or downlink queue the rest of that class is built around.
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
    ): array
    {
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($model['device_type'] ?? 'watch'));
        $catalog = $this->db->genericCapabilities->all($deviceType);
        $supportedKeys = $model !== null
            ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($model['id'] ?? 0))
            : CapabilityCatalog::keysForProtocol($protocol);
        $matrix = CapabilityCatalog::buildCapabilityMatrix($catalog, $supportedKeys);
        $capabilities = [];

        foreach (CapabilityCatalog::sections() as $section => $_label) {
            $capabilities[$section] = [];
        }

        $capabilities['telemetry'] = $this->telemetryCapabilities($model, $protocol, $matrix);

        if ($includeDefaults) {
            foreach ($matrix as $section => $sectionMatrix) {
                if ($section === 'telemetry') {
                    continue;
                }

                foreach ($sectionMatrix as $genericKey => $supported) {
                    if (!$supported) {
                        continue;
                    }
                    if (
                        $this->capabilityRegistry->has($genericKey)
                        && !$this->capabilityRegistry->supportsProtocol($genericKey, $protocol)
                    ) {
                        continue;
                    }

                    if (!array_key_exists($genericKey, $capabilities[$section])) {
                        $entry = $this->defaultCapabilityEntry($protocol, $genericKey);
                        if ($entry === []) {
                            continue;
                        }
                        $capabilities[$section][$genericKey] = $entry;
                    }
                }
            }
        }

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

            if (
                $this->capabilityRegistry->has($genericKey)
                && !$this->capabilityRegistry->supportsProtocol($genericKey, $protocol)
            ) {
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

        foreach ($meta as $genericKey => $metaData) {
            $hasContract = $this->capabilityRegistry->has($genericKey);
            if ($hasContract && !$this->capabilityRegistry->supportsProtocol($genericKey, $protocol)) {
                continue;
            }
            $supportsMultiple = $hasContract && $this->capabilityRegistry->get($genericKey)?->supportsMultipleNativeKeys();

            if (
                !$supportsMultiple
                && count($nativeKeysPerGeneric[$genericKey] ?? []) > 1
            ) {
                continue;
            }

            foreach ($capabilities as $section => &$sectionCaps) {
                if (array_key_exists($genericKey, $sectionCaps)) {
                    if ($hasContract) {
                        $sectionCaps[$genericKey] = $this->capabilityRegistry->responseEntry(
                            $protocol,
                            $genericKey,
                            (string)($nativeKeyForGeneric[$genericKey] ?? ''),
                            $sectionCaps[$genericKey],
                            $metaData,
                        );
                    } else {
                        $sectionCaps[$genericKey] = [
                            'value' => $sectionCaps[$genericKey],
                            '_meta' => $this->enrichCapabilityMeta($genericKey, $protocol, $metaData),
                        ];
                    }
                    break;
                }
            }
            unset($sectionCaps);
        }

        foreach ($capabilities as $section => $sectionCaps) {
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

        foreach ($capabilities as $section => &$sectionCaps) {
            if ($section === 'telemetry') {
                continue;
            }

            foreach ($sectionCaps as $genericKey => &$value) {
                if (
                    !is_array($value)
                    || (array_key_exists('value', $value) && array_key_exists('_meta', $value))
                ) {
                    continue;
                }

                if ($this->capabilityRegistry->has($genericKey)) {
                    if (!$this->capabilityRegistry->supportsProtocol($genericKey, $protocol)) {
                        continue;
                    }
                    $value = $this->capabilityRegistry->responseEntry(
                        $protocol,
                        $genericKey,
                        (string)($nativeKeyForGeneric[$genericKey] ?? ''),
                        $value,
                        $meta[$genericKey] ?? [],
                    );
                } else {
                    $value = [
                        'value' => $value,
                        '_meta' => $this->enrichCapabilityMeta($genericKey, $protocol, $meta[$genericKey] ?? []),
                    ];
                }
            }
            unset($value);
        }
        unset($sectionCaps);

        if ($deviceType === 'ncs') {
            foreach ($matrix as $section => $sectionMatrix) {
                if ($section === 'telemetry') {
                    continue;
                }

                foreach ($sectionMatrix as $genericKey => $supported) {
                    if (!$supported || array_key_exists($genericKey, $capabilities[$section] ?? [])) {
                        continue;
                    }

                    $capabilities[$section][$genericKey] = [
                        'supported' => true,
                    ];
                }
            }
        }

        return $capabilities;
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
            $desired = $this->defaultDesiredPayloadForConfigEntry($entry, $protocol, $genericKey);
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
     * @return array<string, mixed>
     */
    private function defaultDesiredPayloadForConfigEntry(array $entry, string $protocol, string $genericKey): array
    {
        $input = (string)($entry['input'] ?? 'json');
        $field = static fn(int $index = 0): string => (string)($entry['fields'][$index] ?? '');

        return match ($input) {
            'toggle' => [($field(0) ?: 'enabled') => true],
            'number' => [($field(0) ?: 'value') => 0],
            'phone' => [($field(0) ?: 'phone') => ''],
            'text' => [($field(0) ?: 'value') => ''],
            'pushMessage' => ['message' => ''],
            'makeCall' => ['phone' => ''],
            'resetAction', 'requestAction' => [],
            'intervalToggle' => ['enabled' => true, 'intervalMinutes' => 60],
            'intervalHoursToggle' => ['enabled' => true, 'intervalHours' => 2],
            'workingMode' => ['mode' => 1],
            'bloodPressure' => ['systolic' => 120, 'diastolic' => 80],
            'wonlexBloodPressureWarning' => ['switchState' => true, ($field(1) ?: 'reminderValue') => 90],
            'languageTimezone' => ['language' => 0, 'timeZone' => '0'],
            'dualToggle' => ['enabled' => true, 'callCenterOnFall' => false],
            'fallSensitivityLevels' => ['sensitivity' => 5, 'levels' => 8],
            'timeRanges' => ['ranges' => ['08:10-09:30']],
            'timeRange' => ['range' => '21:10-07:30'],
            'wonlexSleepSettings' => [
                'switchState' => true,
                'sleepStartTime' => '220000',
                'sleepEndTime' => '100000',
                'sleepTarget' => 480,
            ],
            'wonlexReminderThreshold' => ['switchState' => true, ($field(1) ?: 'reminderValue') => 90],
            'wonlexHeartRateRange' => [
                'switchState' => true,
                'remindValue' => 120,
                'exerciseSwitchState' => true,
                'exerciseHRMin' => 100,
                'exerciseHRMax' => 140,
                'exerciseRemindValue' => 140,
            ],
            'list' => ['numbers' => array_fill(0, max(1, (int)($entry['limit'] ?? 3)), '')],
            'contacts' => ['contacts' => [['name' => '', 'phone' => '']]],
            'takePills' => [
                'reminderSettings' => [
                    ['time' => '08:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                    ['time' => '09:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                    ['time' => '10:00', 'enabled' => true, 'frequency' => 1, 'custom' => ''],
                ],
                'number' => 1,
                'reminderText' => '',
                'voiceData' => '',
                'voiceMimeType' => 'audio/webm',
            ],
            'soundProfile' => ['mode' => 1],
            default => [],
        };
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

    /**
     * @param array<string, mixed> $capabilities
     * @return array<string, mixed>
     */
    public function flattenWritableCapabilities(string $protocol, array $capabilities): array
    {
        $flattened = [];
        foreach ($capabilities as $section => $entries) {
            if ($section === 'telemetry' || !is_array($entries)) {
                continue;
            }
            foreach ($entries as $key => $value) {
                if (is_array($value) && array_key_exists('supported', $value) && !array_key_exists('value', $value)) {
                    continue;
                }
                $flattened["{$section}.{$key}"] = $this->publicConfigurationValueForGenericKey(
                    $protocol,
                    $key,
                    $this->extractCapabilityValue($value)
                );
            }
        }

        return $flattened;
    }

    private function extractCapabilityValue(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value)) {
            return $value['value'];
        }
        if (is_array($value) && array_key_exists('items', $value) && array_key_exists('_meta', $value)) {
            return $value['items'];
        }

        return $value;
    }

    public function capabilityValuesEqual(mixed $left, mixed $right): bool
    {
        return $this->normalizeComparableValue($left) === $this->normalizeComparableValue($right);
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalizeComparableValue($item), $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string)$key] = $this->normalizeComparableValue($item);
        }
        ksort($normalized);

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $configRows
     * @return array<string, array{updated_at: string, last_status: string, last_command_id: string}>
     */
    public function genericCapabilityRowMeta(array $configRows): array
    {
        $meta = [];
        foreach ($configRows as $row) {
            $nativeKey = $this->storedNativeConfigurationKey($row);
            if ($nativeKey === null) {
                continue;
            }
            $genericKey = CapabilityCatalog::normalizeStoredCapabilityKey(
                (string)($row['config_key'] ?? $nativeKey)
            );
            if ($genericKey === null) {
                continue;
            }
            $updatedAt = (string)($row['desired_updated_at'] ?? '');
            $lastStatus = (string)($row['last_status'] ?? '');
            $existing = $meta[$genericKey] ?? null;
            $isNewer = $existing === null
                || strcmp($updatedAt, (string)$existing['updated_at']) > 0;
            $sameUpdateWithStrongerStatus = $existing !== null
                && $updatedAt === (string)$existing['updated_at']
                && $this->configurationStatusPriority($lastStatus)
                    > $this->configurationStatusPriority((string)$existing['last_status']);
            if ($isNewer || $sameUpdateWithStrongerStatus) {
                $meta[$genericKey] = [
                    'updated_at' => $updatedAt,
                    'last_status' => $lastStatus,
                    'last_command_id' => (string)($row['last_command_id'] ?? ''),
                ];
            }
        }

        return $meta;
    }

    private function configurationStatusPriority(string $status): int
    {
        if ($this->pendingFailureCode($status) !== '') {
            return 3;
        }
        if (in_array($status, ['queued', 'waiting', 'sent'], true)) {
            return 2;
        }
        if ($status === 'acked') {
            return 1;
        }

        return 0;
    }

    public function pendingStatus(string $lastStatus, bool $reportedExists): string
    {
        if ($this->pendingFailureCode($lastStatus) !== '') {
            return 'failed';
        }
        if (!$reportedExists) {
            if ($lastStatus === 'acked') {
                return 'applied';
            }
            return in_array($lastStatus, ['queued', 'waiting', 'sent'], true)
                ? 'waiting_device'
                : 'never_reported';
        }

        return 'diverged';
    }

    public function pendingFailureCode(string $lastStatus): string
    {
        return in_array($lastStatus, [
            'failed',
            'dropped',
            'delivery_failed',
            'retry_exhausted',
            'response_timeout',
        ], true) ? $lastStatus : '';
    }

    public function normalizeCapabilityValue(
        string $protocol,
        string $genericKey,
        string $nativeKey,
        array $desired
    ): mixed
    {
        return $this->capabilityRegistry->fromNative($genericKey, $nativeKey, $desired, $protocol);
    }

    private function publicConfigurationValueForGenericKey(string $protocol, string $genericKey, mixed $value): mixed
    {
        return match ($genericKey) {
            'sos_contacts' => is_array($value)
                ? $this->stringifyPhoneList($value)
                : [],
            'call_whitelist' => is_array($value)
                ? $this->stringifyCallWhitelistValue($protocol, $value)
                : $value,
            default => $value,
        };
    }

    /**
     * @param array<string|int, mixed> $value
     * @return mixed
     */
    private function stringifyPhoneList(array $value): mixed
    {
        if (array_key_exists('numbers', $value) && is_array($value['numbers'])) {
            return self::stringList($value['numbers']);
        }

        if (!array_is_list($value)) {
            return $value;
        }

        return self::stringList($value);
    }

    /**
     * @param array<string|int, mixed> $value
     * @return mixed
     */
    private function stringifyCallWhitelistValue(string $protocol, array $value): mixed
    {
        if ($protocol === 'vivistar-iw') {
            $normalize = self::normalizePublicContactItem(...);
            if (array_key_exists('contacts', $value) && is_array($value['contacts'])) {
                return array_values(array_filter(array_map(
                    $normalize,
                    $value['contacts']
                )));
            }

            if (array_key_exists('numbers', $value) && is_array($value['numbers'])) {
                return array_values(array_filter(array_map(
                    static fn(mixed $phone): ?array => $normalize(['phone' => $phone]),
                    $value['numbers']
                )));
            }

            if (array_is_list($value)) {
                if ($value !== [] && is_array($value[0] ?? null)) {
                    return array_values(array_filter(array_map(
                        $normalize,
                        $value
                    )));
                }

                return array_values(array_filter(array_map(
                    static fn(mixed $phone): ?array => $normalize(['phone' => $phone]),
                    $value
                )));
            }

            return $value;
        }

        if (array_key_exists('numbers', $value) && is_array($value['numbers'])) {
            return self::stringList($value['numbers']);
        }

        if (array_key_exists('contacts', $value) && is_array($value['contacts'])) {
            return self::stringList(array_map(
                static fn(mixed $contact): string => self::normalizePublicContactPhone($contact),
                $value['contacts']
            ));
        }

        if (!array_is_list($value)) {
            if (array_key_exists('phone', $value)) {
                return self::stringList([(string)$value['phone']]);
            }

            return $value;
        }

        if ($value !== [] && is_array($value[0] ?? null)) {
            return self::stringList(array_map(
                static fn(mixed $contact): string => self::normalizePublicContactPhone($contact),
                $value
            ));
        }

        return self::stringList($value);
    }

    /**
     * @param mixed $item
     * @return array{name: string, phone: string}|null
     */
    private static function normalizePublicContactItem(mixed $item): ?array
    {
        if (!is_array($item)) {
            $phone = trim((string)$item);
            if ($phone === '') {
                return null;
            }

            return ['name' => '', 'phone' => $phone];
        }

        $name = trim((string)($item['name'] ?? ''));
        $phone = trim((string)($item['phone'] ?? ''));
        if ($name === '' && $phone === '') {
            return null;
        }
        if ($phone === '') {
            return null;
        }

        return ['name' => $name, 'phone' => $phone];
    }

    /**
     * @param mixed $item
     */
    private static function normalizePublicContactPhone(mixed $item): string
    {
        if (is_array($item)) {
            return trim((string)($item['phone'] ?? ''));
        }

        return trim((string)$item);
    }

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
