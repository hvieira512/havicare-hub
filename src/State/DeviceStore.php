<?php

declare(strict_types=1);

namespace Hub\State;

use Hub\Domain\Capability\EventSeverity;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Log\Logger;
use Hub\Protocol\Adapter\WonlexAdapter;
use Predis\ClientInterface;

final class DeviceStore implements DeviceStoreContract
{
    /** As mudanças de ligação têm lista própria: os alarmes de um radar enchem os eventos em horas. */
    private const CONNECTION_TYPES = ['device.connected', 'device.disconnected'];

    /** @var array<string, true> quem já se sabe ter histórico de ligações, para não o perguntar a cada mensagem */
    private array $withConnectionHistory = [];

    private ?ApiDataAccess $db = null;
    private DeviceRuntimeStore $runtime;
    private DeviceEventStore $events;
    private DeviceCommandStore $commands;
    private DeviceConfigurationProjection $projection;

    private DeviceUpdateNotifier $updates;

    public function __construct(
        ClientInterface $redis,
        private int $limit = 100,
        private string $prefix = 'hub:dashboard',
        ?DeviceUpdateNotifier $updates = null,
    ) {
        $this->updates = $updates ?? new DeviceUpdateNotifier();
        $this->runtime = new DeviceRuntimeStore($redis, $this->limit, $this->prefix);
        $this->projection = new DeviceConfigurationProjection();
        $this->events = new DeviceEventStore($redis, $this->limit, $this->prefix, $this->projection);
        $this->commands = new DeviceCommandStore($redis, $this->runtime, $this->limit, $this->prefix, $this->projection);
    }

    /**
     * Os streams subscrevem aqui para saberem quando o histórico de um dispositivo muda, em
     * vez de o terem de sondar.
     */
    public function updates(): DeviceUpdateNotifier
    {
        return $this->updates;
    }

    public function setDataAccess(?ApiDataAccess $db): void
    {
        $this->db = $db;
        $this->projection->setDataAccess($db);
    }

    public function recordRejectedDevice(
        string $imei,
        string $protocol,
        string $model,
        string $ident,
        string $reason,
        int|string $licenseId = 0,
        ?string $company = null
    ): void {
        $this->db?->dashboardNotifications->record(
            'device_not_authorized',
            $imei,
            $protocol,
            $model,
            $ident,
            $reason,
            $licenseId,
            $company
        );
    }

    public function registerDevice(
        string $imei,
        string $supplier,
        string $model,
        string $deviceType = 'watch',
        int|string $licenseId = 0,
        string $simNumber = '',
        string $deviceId = '',
        string $company = 'null'
    ): void {
        $this->runtime->registerDevice($imei, $supplier, $model, $deviceType, $licenseId, $simNumber, $deviceId, $company);
    }

    public function deleteDevice(string $imei): void
    {
        $this->runtime->deleteDevice($imei);
    }

    public function updateDeviceAssociation(string $imei, string $company, int $licenseId): void
    {
        $this->runtime->updateDeviceAssociation($imei, $company, $licenseId);
    }

    public function deviceSeen(string $imei, array $fields): bool
    {
        $cameOnline = $this->runtime->deviceSeen($imei, $fields);
        // Quem já estava ligado antes de haver histórico começa-o aqui, só no histórico: no
        // MQTT não houve ligação nenhuma.
        if (!$cameOnline && !isset($this->withConnectionHistory[$imei])) {
            if ($this->events->latest($imei, 'connections') === null) {
                $this->appendConnection($imei, [
                    'type' => 'device.connected',
                    'occurredAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                    'device' => ['id' => $imei],
                ]);
            }
            $this->withConnectionHistory[$imei] = true;
        }

        return $cameOnline;
    }

    public function recordGatewaySighting(string $deviceKey, string $gatewayKey, ?int $rssiDbm): void
    {
        $this->runtime->recordGatewaySighting($deviceKey, $gatewayKey, $rssiDbm);
    }

    /** @return array<string, array<string, mixed>> */
    public function gatewaySightings(string $deviceKey): array
    {
        return $this->runtime->gatewaySightings($deviceKey);
    }

    public function deviceOffline(string $imei): bool
    {
        return $this->runtime->deviceOffline($imei);
    }

    public function append(string $imei, string $list, array $payload): void
    {
        if ($list === 'events' && in_array($payload['type'] ?? null, self::CONNECTION_TYPES, true)) {
            $this->appendConnection($imei, $payload);
            return;
        }

        $this->events->append($imei, $list, $list === 'events' ? EventSeverity::stamp($payload) : $payload);
        // O `DeviceService::recent()` serve telemetria, eventos e comandos; a lista crua não vai para
        // o stream, e anunciá-la acordava os ouvintes a cada mensagem de gateway.
        if ($list === 'telemetry' || $list === 'events') {
            $this->updates->notify($imei);
        }
    }

    /**
     * Só as mudanças de estado: um gateway que se reanuncia a cada reinício não é uma reconexão.
     *
     * @param array<string, mixed> $payload
     */
    private function appendConnection(string $imei, array $payload): void
    {
        $this->withConnectionHistory[$imei] = true;
        if (($this->events->latest($imei, 'connections')['type'] ?? null) === $payload['type']) {
            return;
        }

        $this->events->append($imei, 'connections', $payload);
        $this->updates->notify($imei);
    }

    public function recordCommand(string $imei, string $id, array $record): void
    {
        if (($record['protocol'] ?? '') === 'wonlex-json' && !isset($record['ident'])) {
            $bytes = DeviceCommandRecord::wireBytes($record);
            if ($bytes !== '') {
                $decoded = (new WonlexAdapter())->decodeIncoming($bytes);
                if (is_array($decoded) && isset($decoded['ident'])) {
                    $record['ident'] = $decoded['ident'];
                    $record['ref'] = (string)($decoded['ref'] ?? '');
                }
            }
        } elseif (($record['protocol'] ?? '') === 'vivistar-iw' && !isset($record['ident'])) {
            $bytes = DeviceCommandRecord::wireBytes($record);
            if (preg_match('/^IWBP[A-Z0-9]{2},[^,]*,([^,#]+)/', $bytes, $matches) === 1) {
                $record['ident'] = $matches[1];
            }
        }
        $this->commands->recordCommand($imei, $id, $record);
        $this->updates->notify($imei);
    }

    public function retryWaitingCommands(int $retryAfterSeconds, int $timeoutSeconds, int $maxAttempts, callable $dispatch): void
    {
        $this->commands->retryWaitingCommands($retryAfterSeconds, $timeoutSeconds, $maxAttempts, $dispatch);
        $this->updates->notifyAll();
    }

    public function markLatestCommand(string $imei, string $nativeType, array $fields): void
    {
        $this->commands->markLatestCommand($imei, $nativeType, $fields);
        $this->updates->notify($imei);
    }

    public function markCommand(string $imei, string $id, array $fields): void
    {
        $this->commands->markCommand($imei, $id, $fields);
        $this->updates->notify($imei);
    }

    public function isCurrentOperation(string $operationId): bool
    {
        return $this->commands->isCurrentOperation($operationId);
    }

    public function markCommandReply(
        string $imei,
        string $replyNativeType,
        string|int|null $ident = null,
        string $ref = '',
        ?bool $accepted = null,
    ): void {
        $this->commands->markCommandReply($imei, $replyNativeType, $ident, $ref, $accepted);
        $this->updates->notify($imei);
    }

    public function expireWaitingCommands(int $timeoutSeconds): void
    {
        $this->commands->expireWaitingCommands($timeoutSeconds);
        $this->updates->notifyAll();
    }

    public function expireStaleDevices(int $timeoutSeconds, ?string $deviceType = null): array
    {
        return $this->runtime->expireStaleDevices($timeoutSeconds, $deviceType);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function devices(): array
    {
        return $this->runtime->devices();
    }

    public function device(string $imei): array
    {
        return $this->runtime->device($imei);
    }

    /**
     * @param list<string> $imeis
     * @return array<string, array<string, mixed>>
     */
    public function runtimeStates(array $imeis): array
    {
        return $this->runtime->runtimeStates($imeis);
    }

    /**
     * @return list<string>
     */
    public function onlineDeviceImeis(): array
    {
        return $this->runtime->onlineDeviceImeis();
    }

    public function recent(string $imei, string $list, int $sinceSeq = 0): array
    {
        return $this->events->recent($imei, $list, $sinceSeq);
    }

    public function latestSequence(string $imei, string $list): int
    {
        return $this->events->latestSequence($imei, $list);
    }

    public function historyLimit(): int
    {
        return $this->limit;
    }

    public function commands(string $imei): array
    {
        return $this->commands->commands($imei);
    }

    public function findCommand(string $id): ?array
    {
        return $this->commands->findCommand($id);
    }

    public function desiredConfigurations(string $imei): array
    {
        if ($this->db === null) {
            return [];
        }

        $configurations = [];
        foreach ($this->db->deviceConfigurations->allForImei($imei) as $row) {
            $key = trim((string)($row['native_key'] ?? $row['config_key'] ?? ''));
            $payload = $row['desired_payload'] ?? null;
            if ($key !== '' && is_array($payload) && $payload !== []) {
                try {
                    foreach (DeviceConfigurationCatalog::commandPayloads('wonlex-json', $key, $payload) as $built) {
                        $configurations[] = $built;
                    }
                } catch (\Throwable $e) {
                    Logger::channel('hub')->warning('Skipped unbuildable Wonlex configuration', [
                        'imei' => $imei,
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        }

        return $configurations;
    }
}
