<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Ingress\Mqtt\Gateway\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Moko\Bridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * A manutenção do tique publica -- expira gateways parados e pares silenciosos --, e uma
 * publicação que falha é do publicador e não da ingestão. Sem protecção própria subia até ao
 * `IngressRunner`, que a registava como se o ingresso tivesse caído, e levava com ela a
 * varredura dos gateways seguintes.
 */
final class BridgeMaintenanceFailureTest extends TestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const GATEWAY2 = 'c5e390f30bce';

    private float $now = 1000.0;

    public function testAFailedPublishDuringMaintenanceDoesNotEscape(): void
    {
        [$bridge, $mqtt] = $this->bridgeWithTwoIdleGateways();

        $bridge->runDueMaintenance();

        self::assertGreaterThan(0, $mqtt->attempts, 'a manutenção chegou a tentar publicar');
    }

    public function testTheSecondGatewayIsStillExpiredWhenTheFirstPublishFails(): void
    {
        [$bridge, $mqtt] = $this->bridgeWithTwoIdleGateways();

        $bridge->runDueMaintenance();

        self::assertSame(
            2,
            $mqtt->attempts,
            'a falha de um gateway não pode levar consigo a varredura dos restantes',
        );
    }

    /** @return array{0: Bridge, 1: FailingHubMqttBridge} */
    private function bridgeWithTwoIdleGateways(): array
    {
        $mqtt = new FailingHubMqttBridge();
        $bridge = new Bridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::gateway('MKGW3'),
                self::GATEWAY2 => IngressFixtures::gateway('MKGW4'),
            ]),
            $mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
            clock: fn(): float => $this->now,
        );

        foreach ([self::GATEWAY, self::GATEWAY2] as $gateway) {
            $bridge->handleReceivedMessage(
                'havicare-hub/null/0/gw/' . $gateway . '/raw',
                self::heartbeat($gateway),
            );
        }

        // Cala os dois para além do limite de inactividade, e só a partir daqui é que o
        // publicador recusa: o `online` de cada gateway tinha de passar para eles entrarem
        // na lista que a manutenção varre.
        $this->now += 10_000.0;
        $mqtt->failing = true;
        $mqtt->attempts = 0;

        return [$bridge, $mqtt];
    }

    private static function heartbeat(string $gateway): string
    {
        return json_encode([
            'msg_id' => 3004,
            'device_info' => ['mac' => $gateway],
            'data' => ['timestamp' => 0, 'net_interface' => 1, 'wifi_rssi' => -54],
        ], JSON_THROW_ON_ERROR);
    }
}

/** Um publicador que recusa tudo, como o do hub quando o socket de saída está cheio. */
final class FailingHubMqttBridge extends RecordingHubMqttBridge
{
    public bool $failing = false;
    public int $attempts = 0;

    public function publishStatus(
        string $imei,
        array $payload,
        bool $retain = true,
        string $deviceType = 'watch',
        int $licenseId = 0,
        string $company = 'null',
    ): void {
        $this->attempts++;

        if ($this->failing) {
            throw new \RuntimeException('Sending data over the socket failed. Has it been closed?');
        }

        parent::publishStatus($imei, $payload, $retain, $deviceType, $licenseId, $company);
    }
}
