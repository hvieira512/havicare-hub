<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Gateway;

use Hub\Device\ConnectionAnnouncer;
use Hub\Device\HubMqttBridge;
use Hub\Domain\DeviceMetadata;
use Hub\State\DeviceReportStore;

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

    private readonly ConnectionAnnouncer $announcer;

    public function __construct(
        HubMqttBridge $mqttBridge,
        private readonly ?DeviceReportStore $deviceStore,
        private readonly string $protocol,
    ) {
        $this->announcer = new ConnectionAnnouncer($mqttBridge, $deviceStore);
    }

    /**
     * Marca o aparelho como online, com o estado publicado retido.
     *
     * @param array<string, mixed> $device como a whitelist o resolve
     */
    public function markOnline(string $deviceKey, array $device): void
    {
        $wasOnline = $this->online[$deviceKey] ?? false;
        $this->online[$deviceKey] = true;

        // É isto que a dashboard e a API leem para dizer se o aparelho está online; a manutenção
        // também o desliga, e por isso é por aqui que se sabe se isto é uma ligação nova.
        $cameOnline = $this->deviceStore?->deviceSeen($deviceKey, [
            'supplier' => (string)($device['supplier'] ?? ''),
            'model' => (string)($device['model'] ?? ''),
            'deviceType' => (string)($device['deviceType'] ?? ''),
            'licenseId' => DeviceMetadata::normalizeLicenseId($device['licenseId'] ?? 0),
            'company' => (string)($device['company'] ?? 'null'),
            'protocol' => $this->protocol,
            'transport' => 'ble_gateway',
            'online' => '1',
        ]);

        // O estado é retido e vale a cada anúncio; o acontecimento só sai na transição.
        if ($wasOnline && $cameOnline !== true) {
            $this->announcer->status($deviceKey, $device, true);
            return;
        }

        $this->announcer->announce($deviceKey, $device, true);
    }

    /**
     * A ligação BLE caiu: o aparelho afastou-se, ficou sem bateria ou foi desligado.
     *
     * @param array<string, mixed> $device
     */
    public function markOffline(string $deviceKey, array $device): void
    {
        // Sem memória, como depois de um reinício, a queda conta: o estado retido pode dizer `online`.
        if (($this->online[$deviceKey] ?? null) === false) {
            return;
        }
        $this->online[$deviceKey] = false;

        $this->deviceStore?->deviceOffline($deviceKey);
        $this->announcer->announce($deviceKey, $device, false);
    }
}
