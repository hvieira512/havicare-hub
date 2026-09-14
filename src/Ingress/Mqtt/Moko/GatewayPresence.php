<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Moko;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Device\HubMqttBridge;
use Hub\Device\RawPayload;
use Hub\Domain\DeviceMetadata;

/**
 * Se cada gateway está vivo, e o que isso faz sair para fora.
 *
 * Um gateway não se despede: a ligação MQTT dele pode cair sem um `will`, e por isso a única
 * prova de que continua ali é falar. Estar vivo é ter falado há pouco, e quem passa do prazo é
 * dado como desligado por um varrimento -- o irmão do `BraceletPresence`, que responde à mesma
 * pergunta para as pulseiras.
 */
final class GatewayPresence
{
    /** Os gateways que já se anunciaram, pela forma com que a whitelist os resolve. */
    /** @var array<string, array<string, mixed>> */
    private array $online = [];

    /** @var array<string, float> */
    private array $lastSeenAt = [];

    private \Closure $clock;

    public function __construct(
        private readonly HubMqttBridge $mqttBridge,
        private readonly ?DashboardStoreContract $dashboardStore,
        private readonly int $idleTimeoutSeconds,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
    }

    /** Marca o instante em que este gateway falou. Chamado a cada trama dele. */
    public function touch(string $deviceKey): void
    {
        $this->lastSeenAt[$deviceKey] = (float)($this->clock)();
    }

    /**
     * Anuncia o gateway como ligado, se ainda não estava.
     *
     * O estado sai retido e vale a cada anúncio; o acontecimento é da transição. Repetir
     * «Ligado» a cada trama enchia o histórico e escondia o instante em que ele apareceu.
     *
     * @param array<string, mixed> $gateway
     */
    public function markOnline(array $gateway): void
    {
        $deviceKey = (string)$gateway['imei'];
        if (isset($this->online[$deviceKey])) {
            return;
        }
        $this->online[$deviceKey] = $gateway;

        $deviceType = (string)$gateway['deviceType'];
        $licenseId = DeviceMetadata::normalizeLicenseId($gateway['licenseId'] ?? 0);
        $company = (string)($gateway['company'] ?? 'null');
        $commercial = (string)($gateway['commercialName'] ?? '');
        $status = RawPayload::status($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'online', null, $commercial);
        $event = RawPayload::event($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'device.connected', null, null, $commercial);

        $this->mqttBridge->publishStatus($deviceKey, $status, true, $deviceType, $licenseId, $company);
        $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
        $this->dashboardStore?->append($deviceKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
    }

    /** Dá por desligado quem passou do prazo sem falar. */
    public function expireIdle(): void
    {
        $now = (float)($this->clock)();
        foreach ($this->online as $deviceKey => $gateway) {
            if ($now - ($this->lastSeenAt[$deviceKey] ?? $now) < $this->idleTimeoutSeconds) {
                continue;
            }
            $deviceType = (string)$gateway['deviceType'];
            $licenseId = DeviceMetadata::normalizeLicenseId($gateway['licenseId'] ?? 0);
            $company = (string)($gateway['company'] ?? 'null');
            $commercial = (string)($gateway['commercialName'] ?? '');
            $status = RawPayload::status($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'offline', null, $commercial);
            $event = RawPayload::event($deviceKey, (string)$gateway['supplier'], (string)$gateway['model'], 'device.disconnected', null, null, $commercial);

            // Uma publicação que não passa não leva consigo os gateways seguintes; e o
            // gateway só sai da lista depois de o `offline` ter saído, para se retentar.
            try {
                $this->mqttBridge->publishStatus($deviceKey, $status, true, $deviceType, $licenseId, $company);
                $this->mqttBridge->publishEvent($deviceKey, $event, $deviceType, $licenseId, $company);
            } catch (\Throwable $e) {
                $this->mqttBridge->logPublishFailure('hub', $deviceKey, $e);
                continue;
            }

            $this->dashboardStore?->deviceOffline($deviceKey);
            $this->dashboardStore?->append($deviceKey, 'events', $event + ['deviceType' => $deviceType, 'licenseId' => $licenseId]);
            unset($this->online[$deviceKey], $this->lastSeenAt[$deviceKey]);
        }
    }
}
