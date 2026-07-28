<?php

namespace Hub\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Dashboard\DashboardStoreContract;
use Hub\DeviceHubServer;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Log\Logger;

final class DeviceConfigurationUpdateService
{
    public function __construct(
        private DashboardStoreContract $store,
        private DeviceHubServer $hub,
        private ApiDataAccess $db,
        private CapabilityRegistry $capabilities,
    ) {
    }

    /**
     * @param array<string, mixed> $configurations
     * @param array<string, mixed>|null $modelRow
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $device
     * @return array{results?: list<array<string, mixed>>, error?: array<string, string>}
     */
    public function update(
        string $imei,
        array $configurations,
        string $supplier,
        string $model,
        string $protocol,
        ?array $modelRow,
        array $metadata,
        array $device,
        string $requestId = '',
    ): array {
        $enabledCapabilities = $modelRow !== null
            ? array_flip($this->db->modelCapabilities->enabledFeaturesForModelId((int)$modelRow['id']))
            : [];
        $currentByKey = [];
        foreach ($this->db->deviceConfigurations->allForImei($imei) as $row) {
            $normalizedKey = $this->comparisonStoredConfigurationKey(
                (string)($row['native_key'] ?? $row['config_key'] ?? '')
            );
            if ($normalizedKey !== null) {
                $currentByKey[$normalizedKey] = $row;
            }
        }

        $results = [];
        foreach ($configurations as $genericKey => $payload) {
            if (!is_string($genericKey) || !is_array($payload)) {
                return $this->reject($requestId, $imei, '', 'Each config entry must be an object', 'invalid_config_entry');
            }

            $section = CapabilityCatalog::sectionForCapabilityKey($genericKey);
            if ($section === null || $section === 'telemetry') {
                return $this->reject(
                    $requestId,
                    $imei,
                    $genericKey,
                    "Unsupported configuration {$genericKey}",
                    'unsupported_generic_configuration_key'
                );
            }

            if (!isset($enabledCapabilities[$genericKey])) {
                return $this->reject(
                    $requestId,
                    $imei,
                    $genericKey,
                    "Capability {$genericKey} is not enabled for this model",
                    'capability_not_enabled_for_model'
                );
            }

            if ($genericKey === 'medication_reminders' && !array_key_exists('voiceData', $payload)) {
                $existing = $currentByKey[$genericKey]['desired_payload'] ?? null;
                if (is_array($existing) && array_key_exists('voiceData', $existing)) {
                    $payload['voiceData'] = $existing['voiceData'];
                    if (!array_key_exists('voiceMimeType', $payload) && array_key_exists('voiceMimeType', $existing)) {
                        $payload['voiceMimeType'] = $existing['voiceMimeType'];
                    }
                }
            }

            try {
                $nativeUpdates = $this->capabilities->toNative($protocol, $genericKey, $payload);
            } catch (\InvalidArgumentException $e) {
                return $this->reject($requestId, $imei, $genericKey, $e->getMessage());
            }

            $operations = [];
            foreach ($nativeUpdates as $nativeKey => $nativePayload) {
                $normalizedNativeKey = $this->comparisonStoredConfigurationKey($nativeKey) ?? $nativeKey;
                $existingPayload = is_array($currentByKey[$normalizedNativeKey]['desired_payload'] ?? null)
                    ? $currentByKey[$normalizedNativeKey]['desired_payload']
                    : null;
                if ($existingPayload !== null && $this->valuesEqual($existingPayload, $nativePayload)) {
                    continue;
                }

                $result = $this->persistAndApply(
                    $imei,
                    $nativeKey,
                    $nativePayload,
                    $supplier,
                    $model,
                    $protocol,
                    $metadata,
                    $device
                );
                if (isset($result['error'])) {
                    Logger::channel('api')->warning('API device configuration rejected', [
                        'request_id' => $requestId,
                        'imei' => $imei,
                        'config_key' => $genericKey,
                        'native_key' => $nativeKey,
                        'error_code' => $result['error']['code'] ?? 'invalid_config',
                    ]);
                    return $result;
                }

                $currentByKey[$normalizedNativeKey] = [
                    'desired_payload' => $nativePayload,
                ] + ($currentByKey[$normalizedNativeKey] ?? []);
                foreach ($result['operations'] as $operation) {
                    $operations[] = ['nativeKey' => $nativeKey] + $operation;
                }
            }

            if ($operations !== []) {
                $results[] = [
                    'key' => $genericKey,
                    'operations' => $operations,
                ];
            }
        }

        return ['results' => $results];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function persistAndApply(
        string $imei,
        string $nativeKey,
        array $payload,
        string $supplier,
        string $model,
        string $protocol,
        array $metadata,
        array $device,
    ): array {
        if ($protocol === '') {
            return ['error' => ['code' => 'unknown_protocol', 'message' => 'Device protocol could not be resolved']];
        }

        $entry = DeviceConfigurationCatalog::configForProtocol($protocol, $nativeKey);
        if (($entry['transient'] ?? false) === true) {
            return ['error' => ['code' => 'invalid_config', 'message' => "{$nativeKey} is a transient action and must be requested via /requests"]];
        }

        $error = DeviceConfigurationCatalog::validate($protocol, $nativeKey, $payload);
        if ($error !== null) {
            return ['error' => ['code' => 'invalid_config', 'message' => $error]];
        }

        $operations = [];
        $lastId = '';
        $lastStatus = 'dropped';
        $lastCommand = '';
        foreach (DeviceConfigurationCatalog::commandPayloads($protocol, $nativeKey, $payload) as $commandPayload) {
            $command = $commandPayload['command'];
            $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $command, $commandPayload['payload'], [
                'deviceId' => (string)($metadata['deviceId'] ?? $device['deviceId'] ?? ''),
            ]);
            $id = bin2hex(random_bytes(8));
            $status = $this->hub->submitDownlink($imei, $bytes);
            $record = [
                'status' => $status === 'sent' ? 'waiting' : $status,
                'imei' => $imei,
                'protocol' => $protocol,
                'nativeType' => $command,
                'label' => (string)($entry['label'] ?? $nativeKey),
                'configKey' => $nativeKey,
                'expectedReplyTypes' => $entry['expectedReplyTypes'] ?? [],
                'retryable' => true,
                'bytes' => $bytes,
                'attempts' => 1,
                'maxAttempts' => 3,
                'retryDelaySeconds' => 60,
                'lastAttemptAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 60),
                'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            ];
            if ($status === 'sent') {
                $record['sentAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
            }
            if ($status === 'dropped') {
                $record['error'] = 'delivery_failed';
            }

            $this->store->recordCommand($imei, $id, $record);
            $lastId = $id;
            $lastStatus = (string)$record['status'];
            $lastCommand = $command;
            $operations[] = [
                'command' => $command,
                'deliveryStatus' => $record['status'],
                'lastCommandId' => $id,
            ];
        }

        $this->db->deviceConfigurations->saveDesired(
            $imei,
            $nativeKey,
            $protocol,
            $supplier,
            $model,
            $lastCommand,
            $payload,
            $lastStatus,
            $lastId
        );

        return ['operations' => $operations];
    }

    /**
     * @return array{error: array{code: string, message: string}}
     */
    private function reject(
        string $requestId,
        string $imei,
        string $genericKey,
        string $message,
        string $reason = '',
    ): array {
        Logger::channel('api')->warning('API device configuration rejected', array_filter([
            'request_id' => $requestId,
            'imei' => $imei,
            'config_key' => $genericKey,
            'error_code' => 'invalid_config',
            'reason' => $reason,
            'message' => $message,
        ], static fn(mixed $value): bool => $value !== ''));

        return ['error' => ['code' => 'invalid_config', 'message' => $message]];
    }

    private function comparisonStoredConfigurationKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        if (in_array($key, ['whitelistGroup1', 'whitelistGroup2', 'sosNumber1', 'sosNumber2', 'sosNumber3'], true)) {
            return $key;
        }

        return CapabilityCatalog::normalizeStoredCapabilityKey($key) ?? $key;
    }

    private function valuesEqual(mixed $left, mixed $right): bool
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
}
