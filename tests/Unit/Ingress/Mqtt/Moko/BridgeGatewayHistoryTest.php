<?php

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Dashboard\DashboardStore;
use Hub\Ingress\Mqtt\Moko\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Moko\Bridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * O que entra no histórico cru do gateway.
 *
 * Um gateway em "real time scan & immediate report" publica cerca de duas mensagens por
 * segundo, e a lista da dashboard guarda 100 entradas. Enquanto os relatórios de scan foram
 * lá parar, a janela do histórico era de menos de um minuto e as tramas de estado -- as
 * únicas que trazem bateria e cobertura do próprio gateway -- eram despejadas em segundos.
 *
 * A separação é por assunto e não por volume: um relatório de scan descreve os dispositivos
 * retransmitidos, que já têm o seu próprio histórico, e o histórico do gateway é do gateway.
 */
final class BridgeGatewayHistoryTest extends TestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const BRACELET = 'fbd87c59ba8b';

    /** @return array{0: Bridge, 1: RecordingHubMqttBridge, 2: DashboardStore} */
    private function bridge(): array
    {
        $mqtt = new RecordingHubMqttBridge();
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard:gwhistory');

        $bridge = new Bridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::gateway('MKGW3'),
                self::BRACELET => IngressFixtures::bracelet('W6B'),
            ]),
            $mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
            dashboardStore: $store,
        );

        return [$bridge, $mqtt, $store];
    }

    private function deliver(Bridge $bridge, string $payload): void
    {
        $bridge->handleReceivedMessage(
            'havicare-hub/null/0/gw/' . self::GATEWAY . '/raw',
            $payload,
        );
    }

    /** Um relatório de scan: fala dos dispositivos que o gateway ouviu. */
    private function scanPayload(): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => self::GATEWAY],
            'data' => [[
                'type_code' => 7,
                'type' => 'bxp-button',
                'rssi' => -70,
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

    /** Uma trama de estado: fala do próprio gateway. */
    private function statusPayload(): string
    {
        return json_encode([
            'msg_id' => 3004,
            'device_info' => ['mac' => self::GATEWAY],
            'data' => ['network_type' => 'FDD LTE', 'csq' => 24, 'battery_voltage_mv' => 3800],
        ], JSON_THROW_ON_ERROR);
    }

    public function testAScanReportStaysOutOfTheGatewayHistory(): void
    {
        [$bridge, , $store] = $this->bridge();

        $this->deliver($bridge, $this->scanPayload());

        self::assertSame([], $store->recent(self::GATEWAY, 'raw'));
    }

    public function testAStatusFrameIsKeptInTheGatewayHistory(): void
    {
        [$bridge, , $store] = $this->bridge();

        $this->deliver($bridge, $this->statusPayload());

        self::assertNotSame([], $store->recent(self::GATEWAY, 'raw'));
    }

    /**
     * O estado sobrevive à enxurrada. Com os relatórios de scan a entrarem na lista, cem
     * mensagens -- menos de um minuto -- bastavam para não restar uma única trama de estado.
     */
    public function testTheStatusFrameSurvivesAFloodOfScanReports(): void
    {
        [$bridge, , $store] = $this->bridge();

        $this->deliver($bridge, $this->statusPayload());
        for ($i = 0; $i < 150; $i++) {
            $this->deliver($bridge, $this->scanPayload());
        }

        self::assertCount(1, $store->recent(self::GATEWAY, 'raw'));
    }

    /**
     * Nada se perde para quem integra: o histórico da dashboard é que é selectivo, o MQTT
     * continua a levar a série completa.
     */
    public function testEveryFrameIsStillPublishedRaw(): void
    {
        [$bridge, $mqtt] = $this->bridge();

        $this->deliver($bridge, $this->statusPayload());
        $this->deliver($bridge, $this->scanPayload());

        self::assertCount(2, $mqtt->raw);
    }

    /** E o relatório de scan continua a ser despachado para o dispositivo retransmitido. */
    public function testTheScanReportStillReachesTheRelayedDevice(): void
    {
        [$bridge, $mqtt] = $this->bridge();

        $this->deliver($bridge, $this->scanPayload());

        $types = array_column(array_column($mqtt->telemetry, 'payload'), 'type');
        self::assertContains('proximity', $types);
    }
}
