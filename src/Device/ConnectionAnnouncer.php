<?php

declare(strict_types=1);

namespace Hub\Device;

use Hub\Domain\DeviceMetadata;
use Hub\Domain\Liveness;
use Hub\State\DeviceReportStore;

/**
 * Publica uma mudança de ligação de um aparelho sem sessão própria: o estado retido, o
 * acontecimento e a entrada no histórico, como o servidor TCP faz para as suas.
 */
final class ConnectionAnnouncer
{
    public function __construct(
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DeviceReportStore $deviceStore,
    ) {
    }

    /** @param array<string, mixed> $device como a whitelist ou o estado em tempo real o descrevem */
    public function announce(string $imei, array $device, bool $connected): void
    {
        $this->status($imei, $device, $connected);

        [$deviceType, $licenseId, $company] = self::tenant($device);
        $event = TelemetryEnvelope::event(
            $imei,
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
            $connected ? 'device.connected' : 'device.disconnected',
            null,
            null,
            (string)($device['commercialName'] ?? ''),
        );
        $this->mqttBridge->publishAndStore($this->deviceStore, 'events', $imei, $event, $deviceType, $licenseId, $company);
    }

    /**
     * Só o estado retido, sem acontecimento: para quem o repete enquanto a ligação dura.
     *
     * @param array<string, mixed> $device
     */
    public function status(string $imei, array $device, bool $connected, bool $asleep = false): void
    {
        [$deviceType, $licenseId, $company] = self::tenant($device);
        $status = TelemetryEnvelope::status(
            $imei,
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
            Liveness::presence((string)($device['protocol'] ?? ''), $connected, $asleep),
            null,
            (string)($device['commercialName'] ?? ''),
        );
        $this->mqttBridge->publishStatus($imei, $status, true, $deviceType, $licenseId, $company);
    }

    /**
     * @param array<string, mixed> $device
     * @return array{string, int, string}
     */
    private static function tenant(array $device): array
    {
        return [
            DeviceMetadata::normalizeDeviceType((string)($device['deviceType'] ?? '')),
            DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0),
            DeviceMetadata::normalizeCompany((string)($device['company'] ?? 'null')),
        ];
    }
}
