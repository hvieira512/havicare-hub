<?php

namespace Hub\Api\Services;

use Hub\Api\Repository\ApiDataAccess;
use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Dashboard\DashboardStoreContract;
use Hub\DeviceHubServer;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\Capability\CapabilityHelpers;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Domain\Capability\Contacts\WonlexContactCodec;
use Hub\Log\Logger;

final class DeviceConfigurationUpdateService
{
    use CapabilityHelpers;

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
        if ($protocol === 'wonlex-json' && array_key_exists('phonebook', $configurations)) {
            $phonebook = ['phonebook' => $configurations['phonebook']];
            unset($configurations['phonebook']);
            $configurations = $phonebook + $configurations;
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
            if ($protocol === 'wonlex-json' && $genericKey === 'phonebook') {
                $sosValue = $configurations['sos_contacts']
                    ?? ($currentByKey['sos_contacts']['desired_payload'] ?? null)
                    ?? $this->wonlexSelectedFamilyNumbers($currentByKey);
                $payload['sosNumbers'] = $this->wonlexSosNumbers($sosValue);
            }
            if ($protocol === 'wonlex-json' && $genericKey === 'sos_contacts') {
                $payload = [
                    'selectedNumbers' => $this->wonlexSosNumbers($payload),
                    'phonebookContacts' => $this->wonlexPhonebookContacts($currentByKey),
                ];
            }

            // O `sanitizeInput` entra no mesmo `try` que o `toNative`: uma capacidade que
            // valida a entrada ali rejeita-a com a mesma excecao, e fora do `try` isso era
            // um 500 em vez de um `invalid_config` com a razao.
            try {
                $payload = $this->capabilities->sanitizeInput($protocol, $genericKey, $payload);

                // Uma capacidade que o hub aplica sozinho nao tem comandos para entregar: o
                // valor desejado guarda-se e da-se por aplicado. O `stage` ja sabe o que
                // fazer com uma alteracao sem operacoes -- marca-a `confirmed` e a linha
                // `acked` --, por isso o resto do ciclo de vida e o mesmo das outras.
                if (!$this->capabilities->travelsToDevice($genericKey)) {
                    $results[] = $this->stageHubApplied(
                        $imei, $genericKey, $payload, $protocol, $supplier, $model
                    );
                    continue;
                }

                $nativeUpdates = $this->capabilities->toNative($protocol, $genericKey, $payload);
            } catch (\InvalidArgumentException $e) {
                return $this->reject($requestId, $imei, $genericKey, $e->getMessage());
            }

            $operations = [];
            $nativeRows = [];
            foreach ($nativeUpdates as $nativeKey => $nativePayload) {
                $normalizedNativeKey = $this->comparisonStoredConfigurationKey($nativeKey) ?? $nativeKey;
                $existingPayload = is_array($currentByKey[$normalizedNativeKey]['desired_payload'] ?? null)
                    ? $currentByKey[$normalizedNativeKey]['desired_payload']
                    : null;
                $existingStatus = (string)($currentByKey[$normalizedNativeKey]['last_status'] ?? '');
                if (
                    $existingPayload !== null
                    && $this->valuesEqual($existingPayload, $nativePayload)
                    && !in_array($existingStatus, ['created', 'failed', 'retry_exhausted', 'response_timeout'], true)
                ) {
                    continue;
                }

                $prepared = $this->prepareNative(
                    $imei, $nativeKey, $nativePayload, $supplier, $model, $protocol, $metadata, $device
                );
                if (isset($prepared['error'])) {
                    Logger::channel('api')->warning('API device configuration rejected', [
                        'request_id' => $requestId,
                        'imei' => $imei,
                        'config_key' => $genericKey,
                        'native_key' => $nativeKey,
                        'error_code' => $prepared['error']['code'] ?? 'invalid_config',
                    ]);
                    return $prepared;
                }

                $currentByKey[$normalizedNativeKey] = [
                    'desired_payload' => $nativePayload,
                ] + ($currentByKey[$normalizedNativeKey] ?? []);
                $nativeRows[] = $prepared['nativeRow'];
                array_push($operations, ...$prepared['operations']);
            }

            if ($nativeRows !== []) {
                $staged = $this->db->configurationLifecycle->stage(
                    $imei, $genericKey, $payload, $nativeRows, $operations
                );
                $dispatched = [];
                foreach ($staged['operations'] as $operation) {
                    $dispatched[] = $this->dispatchOperation($imei, $operation);
                }
                $results[] = [
                    'key' => $genericKey,
                    'changeId' => $staged['changeId'],
                    'desiredRevision' => $staged['revision'],
                    'operations' => $dispatched,
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
    /**
     * Guarda o valor de uma capacidade que nao viaja, e da-a por aplicada.
     *
     * A chave nativa e a generica: nao ha comando nativo nenhum de que ela seja tradução,
     * e o `native_key` faz parte da chave primaria da `device_configurations`, por isso
     * tem de ser alguma coisa. O `confirmation_mode` diz `local`, que e o que distingue
     * estas linhas de uma que foi confirmada por um dispositivo.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function stageHubApplied(
        string $imei,
        string $genericKey,
        array $payload,
        string $protocol,
        string $supplier,
        string $model,
    ): array {
        $staged = $this->db->configurationLifecycle->stage($imei, $genericKey, $payload, [[
            'nativeKey' => $genericKey,
            'protocol' => $protocol,
            'supplier' => $supplier,
            'model' => $model,
            'command' => '',
            'payload' => $payload,
            'confirmationMode' => 'local',
            'operationId' => '',
        ]], []);

        return [
            'key' => $genericKey,
            'changeId' => $staged['changeId'],
            'desiredRevision' => $staged['revision'],
            'operations' => [],
        ];
    }

    private function prepareNative(
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
        $lastCommand = '';
        $confirmationMode = (string)($entry['confirmationMode']
            ?? ($protocol === 'vivistar-iw' && $nativeKey === 'deviceMeasuringFrequency'
                ? 'ack_only'
                : 'execution_ack'));
        foreach (DeviceConfigurationCatalog::commandPayloads($protocol, $nativeKey, $payload) as $commandPayload) {
            $command = $commandPayload['command'];
            $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $command, $commandPayload['payload'], [
                'deviceId' => (string)($metadata['deviceId'] ?? $device['deviceId'] ?? ''),
            ]);
            $id = bin2hex(random_bytes(16));
            $operations[] = [
                'operationId' => $id,
                'nativeKey' => $nativeKey,
                'nativeType' => $command,
                'protocol' => $protocol,
                'bytes' => $bytes,
                'expectedReplyTypes' => $entry['expectedReplyTypes'] ?? [],
                'confirmationMode' => $confirmationMode,
                'label' => (string)($entry['label'] ?? $nativeKey),
            ];
            $lastCommand = $command;
        }

        return [
            'nativeRow' => [
                'nativeKey' => $nativeKey,
                'protocol' => $protocol,
                'supplier' => $supplier,
                'model' => $model,
                'command' => $lastCommand,
                'payload' => $payload,
                'confirmationMode' => $confirmationMode,
                'operationId' => (string)($operations[array_key_last($operations)]['operationId'] ?? ''),
            ],
            'operations' => $operations,
        ];
    }

    /** @param array<string,mixed> $operation */
    private function dispatchOperation(string $imei, array $operation): array
    {
        $id = (string)$operation['operationId'];
        if (!$this->db->configurationLifecycle->isCurrentOperation($id)) {
            return ['nativeKey' => $operation['nativeKey'], 'command' => $operation['nativeType'], 'deliveryStatus' => 'superseded', 'lastCommandId' => $id];
        }
        $status = $this->hub->submitDownlink($imei, (string)$operation['bytes'], [
            'operationId' => $id,
            'changeId' => (string)$operation['changeId'],
            'genericConfigKey' => (string)$operation['configKey'],
        ]);
        $status = $status === 'sent' ? 'waiting' : $status;
        $error = in_array($status, ['dropped', 'failed'], true) ? 'delivery_failed' : '';
        $this->db->configurationLifecycle->updateOperation($id, $status, $error);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $record = [
            'status' => $status,
            'imei' => $imei,
            'protocol' => $operation['protocol'],
            'nativeType' => $operation['nativeType'],
            'label' => $operation['label'],
            'configKey' => $operation['nativeKey'],
            'genericConfigKey' => $operation['configKey'] ?? '',
            'changeId' => $operation['changeId'],
            'desiredRevision' => $operation['desiredRevision'],
            'operationId' => $id,
            'confirmationMode' => $operation['confirmationMode'],
            'expectedReplyTypes' => $operation['expectedReplyTypes'],
            'retryable' => true,
            'bytes' => $operation['bytes'],
            'attempts' => 1,
            'maxAttempts' => 3,
            'retryDelaySeconds' => 60,
            'lastAttemptAt' => $now,
            'nextRetryAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 60),
            'requestedAt' => $now,
            'lifecycleStatusPersisted' => true,
        ];
        if ($status === 'waiting') {
            $record['sentAt'] = $now;
        }
        if ($error !== '') {
            $record['error'] = $error;
        }
        $this->store->recordCommand($imei, $id, $record);
        return ['nativeKey' => $operation['nativeKey'], 'command' => $operation['nativeType'], 'deliveryStatus' => $status, 'lastCommandId' => $id];
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


    /**
     * @param array<string, array<string, mixed>> $currentByKey
     * @return list<array<string, mixed>>
     */
    private function wonlexPhonebookContacts(array $currentByKey): array
    {
        $payload = $currentByKey['phonebook']['desired_payload'] ?? [];
        if (!is_array($payload)) {
            return [];
        }
        $contacts = $payload['contacts'] ?? $payload['familyNumbers'] ?? $payload;

        return is_array($contacts) && array_is_list($contacts) ? array_values($contacts) : [];
    }

    /**
     * @return list<string>
     */
    private function wonlexSosNumbers(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $items = $value['selectedNumbers']
            ?? $value['numbers']
            ?? $value['contacts']
            ?? $value['sosNumbers']
            ?? $value;
        if (!is_array($items) || !array_is_list($items)) {
            return [];
        }

        $numbers = [];
        foreach ($items as $item) {
            $phone = is_array($item)
                ? WonlexContactCodec::publicPhone($item)
                : WonlexContactCodec::normalizePhone((string)$item);
            if ($phone !== '' && !in_array($phone, $numbers, true)) {
                $numbers[] = $phone;
            }
        }

        return $numbers;
    }

    /**
     * Preserve legacy familyNumber.sosSwitch selections when no SOSNumber row exists yet.
     *
     * @param array<string, array<string, mixed>> $currentByKey
     * @return list<string>
     */
    private function wonlexSelectedFamilyNumbers(array $currentByKey): array
    {
        return array_values(array_filter(array_map(
            static fn(array $contact): string => ($contact['sosSwitch'] ?? false)
                ? WonlexContactCodec::publicPhone($contact)
                : '',
            $this->wonlexPhonebookContacts($currentByKey)
        )));
    }
}
