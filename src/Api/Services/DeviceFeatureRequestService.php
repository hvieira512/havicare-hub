<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Domain\Capability\TimeZoneOffset;
use Hub\Api\Auth\ApiAuthContext;
use Hub\Api\Http\ApiError;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\State\DeviceCommandLog;
use Hub\State\DeviceCommandStore;
use Hub\State\DeviceCommandRecord;
use Hub\Device\DeviceHubServer;
use Hub\Domain\Capability\CapabilityRegistry;
use Hub\Domain\DeviceMetadata;
use Hub\Log\Logger;

/**
 * Pedir a um dispositivo que faça algo: telemetria, acções de capacidades, os comandos em que
 * se transformam e o seu estado. É o lado de escrita da API de dispositivos.
 */
final class DeviceFeatureRequestService
{
    public function __construct(
        private DeviceCommandLog $store,
        private DeviceCommandStore $commands,
        private DeviceHubServer $hub,
        private ApiDataAccess $db,
        private CapabilityRegistry $capabilityRegistry,
        private DeviceCapabilityPresenter $capabilities,
        private DeviceDirectory $directory,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function requestFeature(string $imei, array $payload, ?ApiAuthContext $auth = null, string $requestId = ''): array
    {
        if (!$this->directory->canAccessDevice($imei, $auth)) {
            return $this->reject('telemetry', $requestId, $imei, ApiError::deviceNotFound(), []);
        }

        $feature = trim((string)($payload['feature'] ?? ''));
        $capability = trim((string)($payload['capability'] ?? ''));
        if ($feature === '' && $capability !== '') {
            return $this->requestCapabilityAction($imei, $capability, $payload, $auth, $requestId);
        }
        if ($feature === '') {
            return $this->reject('telemetry', $requestId, $imei, ApiError::invalidField('feature', 'feature is required'), ['reason' => 'missing_feature']);
        }

        $identity = $this->directory->identify($imei);

        $telemetrySupport = $this->capabilities->telemetryCapabilities($identity->modelRow, $identity->protocol);
        if (!($telemetrySupport[$feature]['supported'] ?? false)) {
            return $this->reject('telemetry', $requestId, $imei, ApiError::unsupportedFeature('Feature is not supported for this device'), ['feature' => $feature]);
        }
        if (!($telemetrySupport[$feature]['requestable'] ?? false)) {
            return $this->reject('telemetry', $requestId, $imei, ApiError::featureNotRequestable(), ['feature' => $feature]);
        }

        $result = $this->sendFeatureCommands($imei, $identity->protocol, $feature, $identity->metadata, $identity->device);
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requestCapabilityAction(
        string $imei,
        string $capability,
        array $payload,
        ?ApiAuthContext $auth,
        string $requestId,
    ): array {
        // A reposição de fábrica devolve o aparelho ao servidor do fornecedor: só o hub a pede.
        if ($capability === 'reset_device' && $auth !== null && !$auth->isAdmin()) {
            return $this->reject('capability', $requestId, $imei, ApiError::forbidden(), ['capability' => $capability]);
        }

        $identity = $this->directory->identify($imei);
        $protocol = $identity->protocol;
        $enabled = array_flip($identity->modelRow !== null
            ? $this->db->modelCapabilities->enabledFeaturesForModelId((int)($identity->modelRow['id'] ?? 0))
            : CapabilityCatalog::keysForProtocol($protocol));

        if (!isset($enabled[$capability])) {
            return $this->reject('capability', $requestId, $imei, ApiError::unsupportedFeature('Capability is not supported for this device'), ['capability' => $capability]);
        }

        try {
            $nativeUpdates = $this->capabilityRegistry->toNative($protocol, $capability, $payload['value'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return $this->reject('capability', $requestId, $imei, ApiError::invalidConfig($e->getMessage(), 'value'), ['capability' => $capability, 'message' => $e->getMessage()]);
        }

        // Fora do ciclo: só a calibração do relógio o usa, e lá dentro cada comando pagava uma
        // leitura de todas as configurações do aparelho.
        $timeZone = null;
        $zoneRead = false;

        $commands = [];
        foreach ($nativeUpdates as $nativeKey => $payload) {
            $error = DeviceConfigurationCatalog::validate($protocol, $nativeKey, $payload);
            if ($error !== null) {
                return ApiError::invalidConfig($error, 'value')->toArray();
            }

            $commandPayload = DeviceConfigurationCatalog::commandPayload($protocol, $nativeKey, $payload);
            $command = $commandPayload['command'];
            if (!$zoneRead) {
                $timeZone = $this->storedTimeZone($imei);
                $zoneRead = true;
            }
            $bytes = DeviceCommandCatalog::buildDownlink($protocol, $imei, $command, $commandPayload['payload'], [
                'deviceId' => $identity->metadata !== null
                    ? $identity->metadata->deviceId
                    : (string)($identity->device['deviceId'] ?? ''),
                // Calibrar o relógio precisa de saber a que horas o aparelho se deve pôr, e
                // isso é hora local: o fuso que o hub tem guardado para ele.
                'timeZone' => $timeZone,
            ]);
            $id = bin2hex(random_bytes(8));
            // O valor segue com o comando: «procurar a pulseira» só se distingue de «deixar de a
            // procurar» por ele, e um gateway recebe só o nome da operação.
            $status = $this->hub->submitDownlink($imei, $bytes, [
                'id' => $id,
                'command' => $command,
                'payload' => $payload,
            ]);
            $entry = DeviceConfigurationCatalog::configForProtocol($protocol, $nativeKey) ?? [];
            $expectedReplyTypes = $entry['expectedReplyTypes'] ?? [];
            $commands[] = $this->recordCommand($imei, $id, $status, [
                'protocol' => $protocol,
                'capability' => $capability,
                'nativeType' => $command,
                'expectedReplyTypes' => $expectedReplyTypes,
                'retryable' => false,
                'bytes' => $bytes,
            ], $expectedReplyTypes !== [], gmdate('Y-m-d\\TH:i:s\\Z'));
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

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    private function sendFeatureCommands(
        string $imei,
        string $protocol,
        string $feature,
        ?DeviceMetadata $metadata,
        array $device
    ): array {
        $entries = DeviceCommandCatalog::commandsForFeature($protocol, $feature);
        if ($entries === []) {
            return ApiError::unsupportedFeature('Feature is not supported for this device')->toArray();
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
                'deviceId' => $metadata !== null ? $metadata->deviceId : (string)($device['deviceId'] ?? ''),
            ]);
            $id = bin2hex(random_bytes(8));
            // O identificador deixa o gateway distinguir a reentrega de 60 em 60 s de um novo toque no
            // botão; não entra na chave de de-duplicação da fila, que diz *o que* está em fila.
            $status = $this->hub->submitDownlink($imei, $bytes, ['id' => $id]);
            $requestedAt = time();
            $commands[] = $this->recordCommand($imei, $id, $status, [
                'protocol' => $protocol,
                'requestId' => (string)($entry['id'] ?? $nativeCommand),
                'feature' => $feature,
                'nativeType' => $nativeCommand,
                'expectedReplyTypes' => $entry['expectedReplyTypes'] ?? [],
                'retryable' => true,
                'bytes' => $bytes,
                'attempts' => 1,
                'retryDelaySeconds' => 60,
                'lastAttemptAt' => gmdate('Y-m-d\\TH:i:s\\Z', $requestedAt),
                'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', $requestedAt + 60),
            ], true, gmdate('Y-m-d\\TH:i:s\\Z', $requestedAt));
        }

        return ['status' => 'sent', 'commands' => $commands];
    }

    private function supersedeConflictingFeatureRequests(string $imei, string $feature): void
    {
        $waveforms = ['ecg', 'hrv', 'ppg', 'rr_interval'];
        $isWaveform = in_array($feature, $waveforms, true);

        foreach ($this->commands->commands($imei) as $command) {
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

    /** O fuso que o hub guardou para o aparelho, em HHMM como a TAG `0x1015`: a calibração manda hora local. */
    private function storedTimeZone(string $imei): ?int
    {
        // Uma zona com nome é a regra, e vale pelo desvio de agora: o reportado pode ser o da outra estação.
        $named = $this->db->deviceConfigurations->namedTimeZones($imei)[0]['zone'] ?? null;
        if ($named !== null) {
            return TimeZoneOffset::hhmm($named, new \DateTimeImmutable());
        }

        foreach ($this->db->deviceConfigurations->allForImei($imei) as $row) {
            if (trim((string)($row['config_key'] ?? '')) !== 'time_zone') {
                continue;
            }

            // O reportado fica debaixo de `data`, como a projeção guarda qualquer leitura; o desejado é
            // um mapa simples, e nem sempre foi escrito daqui.
            $reported = $row['reported_payload'] ?? null;
            $candidates = [
                is_array($reported) ? ($reported['data'] ?? null) : null,
                $reported,
                $row['desired_payload'] ?? null,
            ];
            foreach ($candidates as $payload) {
                $zone = is_array($payload) ? ($payload['timeZone'] ?? null) : null;
                if (is_numeric($zone)) {
                    return (int)$zone;
                }
            }
        }

        return null;
    }

    /**
     * Regista a recusa e devolve-a.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function reject(string $kind, string $requestId, string $imei, ApiError $error, array $context): array
    {
        Logger::channel('api')->warning("API {$kind} request rejected", [
            'request_id' => $requestId,
            'imei' => $imei,
            ...$context,
            'error_code' => $error->code,
        ]);

        return $error->toArray();
    }

    /**
     * Guarda o comando que acabou de sair e devolve-o como a API o mostra. Um comando enviado
     * fica `waiting` quando espera resposta.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function recordCommand(string $imei, string $id, string $status, array $record, bool $awaitsReply, string $now): array
    {
        $record = ['status' => $status, 'imei' => $imei] + $record + ['requestedAt' => $now];
        if ($status === 'sent' && $awaitsReply) {
            $record['status'] = 'waiting';
            $record['sentAt'] = $now;
        }
        if ($status === 'dropped') {
            $record['error'] = 'delivery_failed';
        }
        $this->store->recordCommand($imei, $id, $record);

        return DeviceCommandRecord::makeJsonSafe(array_merge($record, ['id' => $id]));
    }

    /** @return array<string, mixed> */
    public function commandStatus(string $id, ?ApiAuthContext $auth = null): array
    {
        $result = $this->commands->findCommand($id);
        if ($result === null) {
            return ApiError::commandNotFound()->toArray();
        }
        $device = is_array($result['device'] ?? null) ? $result['device'] : [];
        $imei = (string)($device['imei'] ?? '');
        if ($imei === '' || !$this->directory->canAccessDevice($imei, $auth)) {
            return ApiError::commandNotFound()->toArray();
        }

        return $result;
    }
}
