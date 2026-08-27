<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\Moko\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Moko\Bridge;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * O encaminhamento de uma pulseira W6B pelo ingress do gateway MOKO.
 *
 * A forma da observação é a que um MKGW3 publica de facto: os beacons MOKO chegam já
 * interpretados, sem bytes de anúncio.
 */
final class BridgeW6bTest extends TestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const GATEWAY2 = 'c5e390f30bce';
    private const BRACELET = 'fbd87c59ba8b';

    /** @param array<string, mixed> $overrides */
    private function scanPayload(array $overrides = [], string $gateway = self::GATEWAY): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => $gateway],
            'data' => [$overrides + [
                'type_code' => 7,
                'type' => 'bxp-button',
                'rssi' => -82,
                'connectable' => 1,
                'mac' => self::BRACELET,
                'frame_type' => 0,
                'passwd_verification' => 1,
                'alarm_status' => 1,
                'trigger_count' => 69,
                'device_id' => '000001',
                'adv_name' => 'MK Button',
                'batt_vol' => 98,
                'x_axis_data' => -4,
                'y_axis_data' => -20,
                'z_axis_data' => 1052,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(RecordingHubMqttBridge $mqtt, bool $linked = true): Bridge
    {
        $path = tempnam(sys_get_temp_dir(), 'moko-w6b-whitelist-');
        file_put_contents($path, json_encode([
            self::GATEWAY => ['supplier' => 'MOKO', 'model' => 'MKGW3', 'deviceType' => 'gateway', 'licenseId' => '1001', 'company' => 'hitcare'],
            self::GATEWAY2 => ['supplier' => 'MOKO', 'model' => 'MKGW4', 'deviceType' => 'gateway', 'licenseId' => '1001', 'company' => 'hitcare'],
            self::BRACELET => ['supplier' => 'MOKO', 'model' => 'W6B', 'deviceType' => 'bracelet', 'licenseId' => '1001', 'company' => 'hitcare'],
        ], JSON_THROW_ON_ERROR));

        $links = new class($linked) implements GatewayDeviceLinkLookup {
            public function __construct(private bool $linked)
            {
            }
            public function isEnabled(string $gatewayDeviceKey, string $linkedDeviceKey): bool
            {
                return $this->linked;
            }
        };

        return new Bridge(
            new FakeMqttSubscriber(),
            new Whitelist($path),
            $mqtt,
            $links,
            new ArrayObservationStateStore(),
        );
    }

    private function deliver(Bridge $bridge, string $payload, string $gateway = self::GATEWAY): void
    {
        $bridge->handleReceivedMessage('havicare-hub/null/0/gw/' . $gateway . '/raw', $payload);
    }

    /**
     * O gateway também se reporta a si próprio, por isso as afirmações olham só para o que
     * foi publicado para a pulseira.
     *
     * @param list<array<string, mixed>> $published
     * @return list<array<string, mixed>>
     */
    private function forBracelet(array $published): array
    {
        return array_values(array_filter(
            $published,
            static fn(array $entry): bool => $entry['imei'] === self::BRACELET,
        ));
    }

    public function testTheFirstSightingPublishesTelemetryButNoHelpCall(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->deliver($this->bridge($mqtt), $this->scanPayload());

        // A contagem de toques é cumulativa, e por isso o primeiro avistamento é só uma
        // linha de base -- não pode repetir o histórico de toques do dispositivo.
        self::assertSame([], array_column($this->forBracelet($mqtt->events), 'type'));
        // A proximidade vem à frente: é reportada por avistamento, antes da telemetria
        // estrangulada que o normalizador produz.
        self::assertSame(['proximity', 'battery', 'motion'], array_column($this->forBracelet($mqtt->telemetry), 'type'));
        self::assertSame(98, $this->forBracelet($mqtt->telemetry)[1]['payload']['data']['percent']);
        self::assertSame('bracelet', $this->forBracelet($mqtt->telemetry)[1]['deviceType']);
    }

    public function testARisingCounterPublishesAHelpCallWithThePressType(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $this->deliver($bridge, $this->scanPayload(['trigger_count' => 69]));
        $this->deliver($bridge, $this->scanPayload(['trigger_count' => 70]));

        self::assertSame(['help_call'], array_column($this->forBracelet($mqtt->events), 'type'));
        $event = $this->forBracelet($mqtt->events)[0]['payload'];
        self::assertSame('single', $event['data']['pressType']);
        self::assertSame(70, $event['data']['triggerCount']);
        self::assertSame(self::GATEWAY, $event['source']['gatewayId']);
    }

    public function testTheThirtySecondBroadcastDoesNotProduceRepeatedHelpCalls(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $this->deliver($bridge, $this->scanPayload(['trigger_count' => 69]));
        // Um toque é anunciado durante 30s, com intervalo de 1s.
        for ($i = 0; $i < 30; $i++) {
            $this->deliver($bridge, $this->scanPayload(['trigger_count' => 70]));
        }

        self::assertCount(1, $this->forBracelet($mqtt->events));
    }

    public function testEachPressModeKeepsItsOwnCounter(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        // As linhas de base do toque simples e do longo.
        $this->deliver($bridge, $this->scanPayload(['frame_type' => 0, 'trigger_count' => 69]));
        $this->deliver($bridge, $this->scanPayload(['frame_type' => 2, 'trigger_count' => 5]));
        // Um toque longo mexe só o contador dele; o simples fica onde estava.
        $this->deliver($bridge, $this->scanPayload(['frame_type' => 2, 'trigger_count' => 6]));
        $this->deliver($bridge, $this->scanPayload(['frame_type' => 0, 'trigger_count' => 69]));

        self::assertSame(['help_call'], array_column($this->forBracelet($mqtt->events), 'type'));
        self::assertSame('long', $this->forBracelet($mqtt->events)[0]['payload']['data']['pressType']);
    }

    public function testAnUnlinkedBraceletIsIgnored(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt, linked: false);

        $this->deliver($bridge, $this->scanPayload(['trigger_count' => 69]));
        $this->deliver($bridge, $this->scanPayload(['trigger_count' => 70]));

        self::assertSame([], $this->forBracelet($mqtt->events));
        self::assertSame([], $this->forBracelet($mqtt->telemetry));
    }

    public function testAnAlarmWithoutTheScanResponseStillRaisesTheHelpCall(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $alarmOnly = ['batt_vol' => null, 'x_axis_data' => null, 'y_axis_data' => null, 'z_axis_data' => null];

        $this->deliver($bridge, $this->scanPayload($alarmOnly + ['trigger_count' => 69]));
        $this->deliver($bridge, $this->scanPayload($alarmOnly + ['trigger_count' => 70]));

        self::assertSame(['help_call'], array_column($this->forBracelet($mqtt->events), 'type'));
    }

    public function testTheGatewayItselfIsStillReported(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->deliver($this->bridge($mqtt), $this->scanPayload());

        // Encaminhar uma pulseira não pode travar o `raw` e o `status` do próprio gateway.
        self::assertNotSame([], $mqtt->raw);
        self::assertSame(self::GATEWAY, $mqtt->raw[0]['imei']);
    }

    public function testEachGatewayThatSeesTheBraceletReportsItsOwnSighting(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        // O mesmo dispositivo, os mesmos valores, dois gateways -- mas RSSI diferente, porque
        // quem o mede é o receptor. Estrangular só por dispositivo colapsava isto numa
        // publicação, e o gateway que ganhasse a corrida ficava com o payload: o
        // `source.gatewayId` saía arbitrário e o outro gateway desaparecia.
        $this->deliver($bridge, $this->scanPayload(['rssi' => -82], self::GATEWAY), self::GATEWAY);
        $this->deliver($bridge, $this->scanPayload(['rssi' => -66], self::GATEWAY2), self::GATEWAY2);

        $motion = array_values(array_filter(
            $this->forBracelet($mqtt->telemetry),
            static fn(array $entry): bool => $entry['type'] === 'motion',
        ));
        self::assertCount(2, $motion);
        self::assertSame(
            [self::GATEWAY, self::GATEWAY2],
            array_map(static fn(array $e): string => $e['payload']['source']['gatewayId'], $motion),
        );
        self::assertSame([-82, -66], array_map(
            static fn(array $e): int => $e['payload']['source']['rssiDbm'],
            $motion,
        ));
    }
}
