<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Device\HubMqttBridge;
use Hub\Device\RawPayload;

/**
 * Se cada pulseira está alcançável, e o que isso faz sair para fora.
 *
 * Ao contrário de um relógio, a pulseira não mantém ligação: está online enquanto um gateway
 * tiver sessão aberta com ela. Guardar esse estado, publicá-lo retido e anunciar a transição
 * são três coisas que andam sempre juntas, e é por isso que saíram juntas da `Bridge`.
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
        private readonly ?DashboardStoreContract $dashboardStore,
    ) {
    }

    /**
     * Marca a pulseira como online.
     *
     * Ao contrário de um relógio, ela não mantém ligação: está online enquanto um gateway
     * tiver sessão aberta. O estado é publicado retido, para que quem subscreva a seguir o
     * receba sem esperar pela próxima ronda.
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

        // É isto que a dashboard e a API leem para dizer se o aparelho está online. Sem
        // esta linha o estado sai no MQTT e mais nada, e o ecrã continua a dizer offline.
        $this->dashboardStore?->deviceSeen($deviceKey, [
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

        // O estado é retido e vale a cada anúncio; o acontecimento é da transição. Repetir
        // «Ligado» a cada batimento enchia o histórico e escondia o instante em que a
        // pulseira se ligou de facto.
        if ($wasOnline) {
            return;
        }

        $this->announce($deviceKey, $device, $licenseId, $company, 'device.connected');
    }

    /**
     * A ligação BLE caiu: a pulseira afastou-se, ficou sem bateria ou foi desligada.
     *
     * Sem isto o ecrã continuava a mostrá-la ligada até o varrimento de aparelhos parados
     * dar por ela, o que é bastante depois de já não haver ninguém a quem entregar um
     * comando -- e a fila de espera continuaria a ser drenada contra um aparelho ausente.
     *
     * @param array<string, mixed> $device
     */
    public function markOffline(string $deviceKey, array $device, int $licenseId, string $company): void
    {
        if (($this->online[$deviceKey] ?? false) === false) {
            return;
        }
        $this->online[$deviceKey] = false;

        $this->dashboardStore?->deviceOffline($deviceKey);

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
        $this->dashboardStore?->append($deviceKey, 'events', $event + [
            'deviceType' => 'bracelet',
            'licenseId' => $licenseId,
        ]);
    }
}
