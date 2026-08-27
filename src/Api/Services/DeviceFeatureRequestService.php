<?php

namespace Hub\Api\Services;

use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Dashboard\DashboardStoreContract;
use Hub\Dashboard\DeviceCommandRecord;
use Hub\DeviceHubServer;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;

/**
 * Pedir a um dispositivo que faça algo: pedidos de telemetria e acções de capacidades, os
 * comandos em que se transformam, e o estado de um já enviado.
 *
 * É o lado de escrita da API de dispositivos, e a única parte que vai ao hub. Ler um
 * dispositivo não pertence aqui.
 */
final class DeviceFeatureRequestService
{
    public function __construct(
        private DashboardStoreContract $store,
        private Whitelist $whitelist,
        private DeviceHubServer $hub,
        private ApiDataAccess $db,
        private CapabilityRegistry $capabilityRegistry,
        private DeviceCapabilityPresenter $capabilities,
        private DeviceDirectory $directory,
    ) {
    }

    public function requestFeature(string $imei, string $body, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            Logger::channel('api')->warning('API telemetry request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'not_found',
            ]);
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            Logger::channel('api')->warning('API telemetry request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'invalid_json',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'Invalid JSON']];
        }

        $feature = trim((string)($decoded['feature'] ?? ''));
        $capability = trim((string)($decoded['capability'] ?? ''));
        if ($feature === '' && $capability !== '') {
            return $this->requestCapabilityAction($imei, $decoded, $auth, $requestId);
        }
        if ($feature === '') {
            Logger::channel('api')->warning('API telemetry request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'error_code' => 'invalid_request',
                'reason' => 'missing_feature',
            ]);
            return ['error' => ['code' => 'invalid_request', 'message' => 'feature is required']];
        }

        $device = $this->directory->deviceSnapshot($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = (string)($device['protocol'] ?? $this->directory->protocolForModel($supplier, $model));
        $modelRow = $this->directory->modelForSupplierAndName($supplier, $model);

        $telemetrySupport = $this->capabilities->telemetryCapabilities($modelRow, $protocol);
        if (!($telemetrySupport[$feature]['supported'] ?? false)) {
            Logger::channel('api')->warning('API telemetry request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'feature' => $feature,
                'error_code' => 'unsupported_feature',
            ]);
            return ['error' => ['code' => 'unsupported_feature', 'message' => 'Feature is not supported for this device']];
        }
        if (!($telemetrySupport[$feature]['requestable'] ?? false)) {
            Logger::channel('api')->warning('API telemetry request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'feature' => $feature,
                'error_code' => 'feature_not_requestable',
            ]);
            return ['error' => ['code' => 'feature_not_requestable', 'message' => 'Feature cannot be requested for this device']];
        }

        $result = $this->sendFeatureCommands($imei, $protocol, $feature, $metadata, $device);
        Logger::channel('api')->info('API telemetry request processed', [
            'request_id' => $requestId,
            'imei' => $imei,
            'feature' => $feature,
            'status' => $result['status'] ?? null,
            'error_code' => $result['error']['code'] ?? null,
            'command_count' => count($result['commands'] ?? []),
        ]);

        if (isset($result['error'])) {
            return $result;
        }

        $requestStatus = 'ok';
        if (($result['commands'] ?? []) !== []) {
            $statuses = array_values(array_unique(array_map(
                static fn(array $command): string => (string)($command['status'] ?? 'unknown'),
                $result['commands']
            )));
            $requestStatus = count($statuses) === 1 ? $statuses[0] : 'partial';
        }

        return [
            'status' => $requestStatus,
            'feature' => $feature,
            'commands' => $result['commands'] ?? [],
        ];
    }

    private function requestCapabilityAction(string $imei, array $decoded, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        $capability = trim((string)($decoded['capability'] ?? ''));
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            Logger::channel('api')->warning('API capability request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'capability' => $capability,
                'error_code' => 'not_found',
            ]);
            return ['error' => ['code' => 'not_found', 'message' => 'Device was not found']];
        }

        if ($capability === '') {
            return ['error' => ['code' => 'invalid_request', 'message' => 'capability is required']];
        }

        $device = $this->directory->deviceSnapshot($imei);
        $metadata = $this->whitelist->getMetadata($imei) ?? [];
        $supplier = (string)($device['supplier'] ?? ($metadata['supplier'] ?? ''));
        $model = (string)($device['model'] ?? ($metadata['model'] ?? ''));
        $protocol = (string)($device['protocol'] ?? $this->directory->protocolForModel($supplier, $model));
        $modelRow = $this->directory->modelForSupplierAndName($supplier, $model);
        $enabled = array_flip($modelRow !== null
            ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($modelRow['id'] ?? 0))
            : CapabilityCatalog::keysForProtocol($protocol));

        if (!isset($enabled[$capability])) {
            Logger::channel('api')->warning('API capability request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'capability' => $capability,
                'error_code' => 'unsupported_feature',
            ]);
            return ['error' => ['code' => 'unsupported_feature', 'message' => 'Capability is not supported for this device']];
        }

        try {
            $nativeUpdates = $this->capabilityRegistry->toNative($protocol, $capability, $decoded['value'] ?? null);
        } catch (\InvalidArgumentException $e) {
            Logger::channel('api')->warning('API capability request rejected', [
                'request_id' => $requestId,
                'imei' => $imei,
                'capability' => $capability,
                'error_code' => 'invalid_config',
                'message' => $e->getMessage(),
            ]);
            return ['error' => ['code' => 'invalid_config', 'message' => $e->getMessage()]];
        }

        $commands = [];
        foreach ($nativeUpdates as $nativeKey => $payload) {
            $error = DeviceConfigurationCatalog::validate($protocol, $nativeKey, $payload);
            if ($error !== null) {
                return ['error' => ['code' => 'invalid_config', 'message' => $error]];
            }

            $commandPayload = DeviceConfigurationCatalog::commandPayload($protocol, $nativeKey, $payload);
            $command = $commandPayload['command'];
            $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $command, $commandPayload['payload'], [
                'deviceId' => (string)($metadata['deviceId'] ?? $device['deviceId'] ?? ''),
            ]);
            $id = bin2hex(random_bytes(8));
            $status = $this->hub->submitDownlink($imei, $bytes);
            $entry = DeviceConfigurationCatalog::configForProtocol($protocol, $nativeKey) ?? [];
            $expectedReplyTypes = $entry['expectedReplyTypes'] ?? [];
            $record = [
                'status' => $status,
                'imei' => $imei,
                'protocol' => $protocol,
                'capability' => $capability,
                'nativeType' => $command,
                'label' => (string)($entry['label'] ?? $nativeKey),
                'expectedReplyTypes' => $expectedReplyTypes,
                'retryable' => false,
                'bytes' => $bytes,
                'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            ];
            if ($status === 'sent' && $expectedReplyTypes !== []) {
                $record['status'] = 'waiting';
                $record['sentAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
            }
            if ($status === 'dropped') {
                $record['error'] = 'delivery_failed';
            }
            $this->store->recordCommand($imei, $id, $record);
            $commands[] = DeviceCommandRecord::makeJsonSafe(array_merge($record, ['id' => $id]));
        }

        $statuses = array_values(array_unique(array_map(
            static fn(array $command): string => (string)($command['status'] ?? 'unknown'),
            $commands
        )));

        return [
            'status' => count($statuses) === 1 ? $statuses[0] : 'partial',
            'capability' => $capability,
            'commands' => $commands,
        ];
    }

    private function sendFeatureCommands(string $imei, string $protocol, string $feature, array $metadata, array $device): array
    {
        $entries = DeviceCommandCatalog::commandsForFeature($protocol, $feature);
        if ($entries === []) {
            return ['error' => ['code' => 'unsupported_feature', 'message' => 'Feature is not supported for this device']];
        }

        $this->supersedeConflictingFeatureRequests($imei, $feature);

        $commands = [];
        foreach ($entries as $entry) {
            $nativeCommand = (string)($entry['command'] ?? '');
            if ($nativeCommand === '') {
                continue;
            }
            $nativePayload = $protocol === 'wonlex-json'
                ? ($entry['data'] ?? [])
                : ['fields' => $entry['data'] ?? []];
            $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $nativeCommand, $nativePayload, [
                'deviceId' => (string)($metadata['deviceId'] ?? $device['deviceId'] ?? ''),
            ]);
            $id = bin2hex(random_bytes(8));
            $status = $this->hub->submitDownlink($imei, $bytes);
            $requestedAt = time();
            $record = [
                'status' => $status,
                'imei' => $imei,
                'protocol' => $protocol,
                'requestId' => (string)($entry['id'] ?? $nativeCommand),
                'feature' => $feature,
                'nativeType' => $nativeCommand,
                'label' => (string)($entry['label'] ?? $nativeCommand),
                'expectedReplyTypes' => $entry['expectedReplyTypes'] ?? [],
                'retryable' => true,
                'bytes' => $bytes,
                'attempts' => 1,
                'maxAttempts' => 3,
                'retryDelaySeconds' => 60,
                'lastAttemptAt' => gmdate('Y-m-d\\TH:i:s\\Z', $requestedAt),
                'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', $requestedAt + 60),
                'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z', $requestedAt),
            ];
            if ($status === 'sent') {
                $record['status'] = 'waiting';
                $record['sentAt'] = gmdate('Y-m-d\\TH:i:s\\Z', $requestedAt);
            }
            if ($status === 'dropped') {
                $record['error'] = 'delivery_failed';
            }
            $this->store->recordCommand($imei, $id, $record);
            $commands[] = DeviceCommandRecord::makeJsonSafe(array_merge($record, ['id' => $id]));
        }

        return ['status' => 'sent', 'commands' => $commands];
    }

    private function supersedeConflictingFeatureRequests(string $imei, string $feature): void
    {
        $waveforms = ['ecg', 'hrv', 'ppg', 'rr_interval'];
        $isWaveform = in_array($feature, $waveforms, true);

        foreach ($this->store->commands($imei) as $command) {
            if (!in_array((string)($command['status'] ?? ''), ['queued', 'waiting'], true)) {
                continue;
            }

            $pendingFeature = (string)($command['feature'] ?? '');
            $conflicts = $pendingFeature === $feature
                || ($isWaveform && in_array($pendingFeature, $waveforms, true));
            if (!$conflicts) {
                continue;
            }

            $id = (string)($command['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $this->store->recordCommand($imei, $id, array_merge($command, [
                'status' => 'superseded',
                'error' => '',
                'lastError' => '',
            ]));
        }
    }

    public function commandStatus(string $id, ?ApiAuthContext $auth = null): array
    {
        $result = $this->store->findCommand($id);
        if ($result === null) {
            return ['error' => ['code' => 'not_found', 'message' => 'Command was not found']];
        }
        $device = is_array($result['device'] ?? null) ? $result['device'] : [];
        $imei = (string)($device['imei'] ?? '');
        if ($imei === '' || !$this->directory->canAccessDevice($imei, $auth, $device)) {
            return ['error' => ['code' => 'not_found', 'message' => 'Command was not found']];
        }

        return $result;
    }
}
