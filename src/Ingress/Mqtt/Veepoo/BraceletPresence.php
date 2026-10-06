<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\State\DeviceReportStore;
use Hub\Device\HubMqttBridge;
use Hub\Device\RawPayload;

/**
 * Se cada pulseira está alcançável: está online enquanto um gateway tiver sessão aberta com ela.
 */
final class BraceletPresence
{
    private const PROTOCOL = 'veepoo-ble';

    /**
     * Se cada pulseira estava alcançável da última vez que o gateway falou dela.
     *
     * @var array<string, bool>
     */
    private array $online = [];

    public function __construct(
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DeviceReportStore $deviceStore,
    ) {
    }

    /**
     * Marca a pulseira como online, com o estado publicado retido.
     *
     * @param array<string, mixed> $device
     */
    public function markOnline(string $deviceKey, array $device, int $licenseId, string $company): void
    {
        $supplier = (string)($device['supplier'] ?? '');
        $model = (string)($device['model'] ?? '');
        $commercial = (string)($device['commercialName'] ?? '');
        $wasOnline = $this->online[$deviceKey] ?? false;
        $this->online[$deviceKey] = true;

        // É isto que a dashboard e a API leem para dizer se o aparelho está online.
        $this->deviceStore?->deviceSeen($deviceKey, [
            'supplier' => $supplier,
            'model' => $model,
            'deviceType' => 'bracelet',
            'licenseId' => $licenseId,
            'company' => $company,
            'protocol' => self::PROTOCOL,
            'transport' => 'ble_gateway',
            'online' => '1',
        ]);

        $status = RawPayload::status($deviceKey, $supplier, $model, 'online', null, $commercial);
        $this->mqttBridge->publishStatus($deviceKey, $status, true, 'bracelet', $licenseId, $company);

        // O estado é retido e vale a cada anúncio; o acontecimento só sai na transição.
        if ($wasOnline) {
            return;
        }

        $this->announce($deviceKey, $device, $licenseId, $company, 'device.connected');
    }

    /**
     * A ligação BLE caiu: a pulseira afastou-se, ficou sem bateria ou foi desligada.
     *
     * @param array<string, mixed> $device
     */
    public function markOffline(string $deviceKey, array $device, int $licenseId, string $company): void
    {
        if (($this->online[$deviceKey] ?? false) === false) {
            return;
        }
        $this->online[$deviceKey] = false;

        $this->deviceStore?->deviceOffline($deviceKey);

        $status = RawPayload::status(
            $deviceKey,
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
            'offline',
            null,
            (string)($device['commercialName'] ?? ''),
        );
        $this->mqttBridge->publishStatus($deviceKey, $status, true, 'bracelet', $licenseId, $company);

        $this->announce($deviceKey, $device, $licenseId, $company, 'device.disconnected');
    }

    /**
     * Publica um acontecimento de ligação no MQTT e no histórico do aparelho.
     *
     * @param array<string, mixed> $device
     */
    private function announce(string $deviceKey, array $device, int $licenseId, string $company, string $type): void
    {
        $event = RawPayload::event(
            $deviceKey,
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
            $type,
            null,
            null,
            (string)($device['commercialName'] ?? ''),
        );

        $this->mqttBridge->publishEvent($deviceKey, $event, 'bracelet', $licenseId, $company);
        $this->deviceStore?->append($deviceKey, 'events', $event + [
            'deviceType' => 'bracelet',
            'licenseId' => $licenseId,
        ]);
    }
}
