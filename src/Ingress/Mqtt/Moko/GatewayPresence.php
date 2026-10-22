<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Device\ConnectionAnnouncer;
use Hub\Device\HubMqttBridge;
use Hub\State\DeviceReportStore;

/**
 * Se cada gateway está vivo: um gateway pode cair sem `will`, e por isso estar vivo é ter
 * falado há pouco. Quem passa do prazo é dado como desligado por um varrimento.
 */
final class GatewayPresence
{
    /**
     * Os gateways que já se anunciaram, como a whitelist os resolve.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $online = [];

    /** @var array<string, float> */
    private array $lastSeenAt = [];

    private \Closure $clock;

    private readonly ConnectionAnnouncer $announcer;

    public function __construct(
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DeviceReportStore $deviceStore,
        private readonly int $idleTimeoutSeconds,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
        $this->announcer = new ConnectionAnnouncer($mqttBridge, $deviceStore);
    }

    /** Marca o instante em que este gateway falou. Chamado a cada trama dele. */
    public function touch(string $deviceKey): void
    {
        $this->lastSeenAt[$deviceKey] = (float)($this->clock)();
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

    /** Dá por desligado quem passou do prazo sem falar. */
    public function expireIdle(): void
    {
        $now = (float)($this->clock)();
        foreach ($this->online as $deviceKey => $gateway) {
            if ($now - ($this->lastSeenAt[$deviceKey] ?? $now) < $this->idleTimeoutSeconds) {
                continue;
            }

            // Só sai da lista depois de o `offline` sair, para se retentar; os outros seguem.
            try {
                $this->announcer->announce((string)$deviceKey, $gateway, false);
            } catch (\Throwable $e) {
                $this->mqttBridge->logPublishFailure('hub', (string)$deviceKey, $e);
                continue;
            }

            $this->deviceStore?->deviceOffline((string)$deviceKey);
            unset($this->online[$deviceKey], $this->lastSeenAt[$deviceKey]);
        }
    }
}
