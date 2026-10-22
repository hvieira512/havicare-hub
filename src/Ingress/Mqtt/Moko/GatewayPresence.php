<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Device\ConnectionAnnouncer;
use Hub\Device\HubMqttBridge;
use Hub\State\DeviceReportStore;

/**
 * Anuncia cada gateway ligado. Desligado dá-o a manutenção do hub, quando se cala para lá do que
 * o protocolo dele aguenta (`Liveness`): um gateway pode cair sem `will`.
 */
final class GatewayPresence
{
    /**
     * Os gateways que já se anunciaram, como a whitelist os resolve.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $online = [];

    private readonly ConnectionAnnouncer $announcer;

    public function __construct(HubMqttBridge $mqttBridge, ?DeviceReportStore $deviceStore)
    {
        $this->announcer = new ConnectionAnnouncer($mqttBridge, $deviceStore);
    }

    /**
     * Anuncia o gateway como ligado, se ainda não estava. `$cameOnline` diz que o Redis o tinha
     * por desligado.
     *
     * @param array<string, mixed> $gateway
     */
    public function markOnline(array $gateway, bool $cameOnline = false): void
    {
        $deviceKey = (string)$gateway['imei'];
        if (isset($this->online[$deviceKey]) && !$cameOnline) {
            return;
        }
        $this->online[$deviceKey] = $gateway;
        $this->announcer->announce($deviceKey, $gateway, true);
    }
}
