<?php

declare(strict_types=1);

namespace Hub\Device;

use Hub\Domain\DeviceMetadata;
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
        $supplier = (string)($device['supplier'] ?? '');
        $model = (string)($device['model'] ?? '');
        $commercial = (string)($device['commercialName'] ?? '');
        $deviceType = DeviceMetadata::normalizeDeviceType((string)($device['deviceType'] ?? ''));
        $licenseId = DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0);
        $company = DeviceMetadata::normalizeCompany((string)($device['company'] ?? 'null'));

        $status = RawPayload::status($imei, $supplier, $model, $connected ? 'online' : 'offline', null, $commercial);
        $event = RawPayload::event($imei, $supplier, $model, $connected ? 'device.connected' : 'device.disconnected', null, null, $commercial);

        $this->mqttBridge->publishStatus($imei, $status, true, $deviceType, $licenseId, $company);
        $this->mqttBridge->publishEvent($imei, $event, $deviceType, $licenseId, $company);
        $this->deviceStore?->append($imei, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
    }
}
