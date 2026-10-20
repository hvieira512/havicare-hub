<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\State\DeviceReportStore;
use Hub\Device\HubMqttBridge;
use Hub\Device\RawPayload;

/**
 * Se cada aparelho servido por gateway GATT está alcançável: está online enquanto um gateway
 * tiver sessão aberta com ele.
 */
final class BraceletPresence
{
    /**
     * Se cada pulseira estava alcançável da última vez que o gateway falou dela.
     *
     * @var array<string, bool>
     */
    private array $online = [];

    public function __construct(
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DeviceReportStore $deviceStore,
        private readonly string $protocol = 'veepoo-ble',
        private readonly string $deviceType = 'bracelet',
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

        // É isto que a dashboard e a API leem para dizer se o aparelho está online; a manutenção
        // também o desliga, e por isso é por aqui que se sabe se isto é uma ligação nova.
        $cameOnline = $this->deviceStore?->deviceSeen($deviceKey, [
            'supplier' => $supplier,
            'model' => $model,
            'deviceType' => $this->deviceType,
            'licenseId' => $licenseId,
            'company' => $company,
            'protocol' => $this->protocol,
            'transport' => 'ble_gateway',
            'online' => '1',
        ]);

        $status = RawPayload::status($deviceKey, $supplier, $model, 'online', null, $commercial);
        $this->mqttBridge->publishStatus($deviceKey, $status, true, $this->deviceType, $licenseId, $company);

        // O estado é retido e vale a cada anúncio; o acontecimento só sai na transição.
        if ($wasOnline && $cameOnline !== true) {
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
        // Sem memória, como depois de um reinício, a queda conta: o estado retido pode dizer `online`.
        if (($this->online[$deviceKey] ?? null) === false) {
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
        $this->mqttBridge->publishStatus($deviceKey, $status, true, $this->deviceType, $licenseId, $company);

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

        $this->mqttBridge->publishEvent($deviceKey, $event, $this->deviceType, $licenseId, $company);
        $this->deviceStore?->append($deviceKey, 'events', $event + [
            'deviceType' => $this->deviceType,
            'licenseId' => $licenseId,
        ]);
    }
}
