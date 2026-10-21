<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\State\DeviceStore;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Moko\MokoBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Uma mensagem por avistamento e por gateway (`docs/05-gateways-ble.md` §5): o hub reporta o sinal, limiares e alarmes são do cliente. */
final class BridgeProximityTest extends TestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const GATEWAY2 = 'c5e390f30bce';
    private const BRACELET = 'fbd87c59ba8b';

    private float $now = 1000.0;

    /** @param array<string, mixed> $overrides */
    private function scanPayload(array $overrides = [], string $gateway = self::GATEWAY): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => $gateway],
            'data' => [$overrides + [
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

    /** @return array{0: MokoBridge, 1: RecordingHubMqttBridge, 2: DeviceStore} */
    private function bridge(): array
    {
        $mqtt = new RecordingHubMqttBridge();
        $store = new DeviceStore(new InMemoryRedisClient());

        $bridge = new MokoBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::gateway('MKGW3'),
                self::GATEWAY2 => IngressFixtures::gateway('MKGW4'),
                self::BRACELET => IngressFixtures::bracelet('W6B'),
            ]),
            $mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
            deviceStore: $store,
            clock: fn(): float => $this->now,
        );

        return [$bridge, $mqtt, $store];
    }

    private function deliver(MokoBridge $bridge, string $payload, string $gateway = self::GATEWAY): void
    {
        $bridge->handleReceivedMessage('havicare-hub/null/0/gw/' . $gateway . '/raw', $payload);
    }

    /**
     * @param list<array<string, mixed>> $published
     * @return list<array<string, mixed>>
     */
    private function proximity(array $published): array
    {
        return array_values(array_filter(
            $published,
            static fn(array $entry): bool => ($entry['payload']['type'] ?? '') === 'proximity',
        ));
    }

    public function testEverySightingIsReportedEvenWhenTheOtherTelemetryIsThrottled(): void
    {
        [$bridge, $mqtt] = $this->bridge();

        // O mesmo payload três vezes: a bateria e o movimento são suprimidos depois do primeiro,
        // mas o sinal sai todas as vezes, senão a série do cliente fica com buracos.
        $this->deliver($bridge, $this->scanPayload(['rssi' => -70]));
        $this->deliver($bridge, $this->scanPayload(['rssi' => -55]));
        $this->deliver($bridge, $this->scanPayload(['rssi' => -80]));

        $proximity = $this->proximity($mqtt->telemetry);
        self::assertCount(3, $proximity);
        self::assertSame(
            [-70, -55, -80],
            array_map(static fn(array $e): int => $e['payload']['data']['rssiDbm'], $proximity),
        );

        $throttled = array_values(array_filter(
            $mqtt->telemetry,
            static fn(array $e): bool => ($e['payload']['type'] ?? '') === 'battery',
        ));
        self::assertCount(1, $throttled, 'a bateria não mudou, e por isso fica estrangulada');
    }

    public function testCarriesTheWindowStatisticsAClientDecidesOn(): void
    {
        [$bridge, $mqtt] = $this->bridge();
        foreach ([-77, -78, -77] as $rssi) {
            $this->deliver($bridge, $this->scanPayload(['rssi' => $rssi]));
            $this->now += 1.0;
        }
        // Passar a andar pelo gateway: uma leitura próxima só.
        $this->deliver($bridge, $this->scanPayload(['rssi' => -52]));

        $proximity = $this->proximity($mqtt->telemetry);
        $data = end($proximity)['payload']['data'];
        self::assertSame(self::GATEWAY, $data['gatewayId'], 'o par tem de vir no data, e não só no source');
        self::assertSame('measured', $data['state']);
        self::assertSame(-52, $data['rssiDbm']);
        self::assertSame(-52, $data['rssiMaxDbm'], 'a passagem só se vê no máximo');
        self::assertSame(-77, $data['rssiMedianDbm'], 'e não pode mexer na mediana');
        self::assertSame(-78, $data['rssiMinDbm']);
        self::assertSame(4, $data['samples']);
        self::assertSame(5, $data['windowSeconds']);
    }

    public function testSignalReportsStayOutOfTheDeviceHistory(): void
    {
        [$bridge, , $store] = $this->bridge();
        foreach (range(1, 5) as $i) {
            $this->deliver($bridge, $this->scanPayload(['rssi' => -60 - $i]));
            $this->now += 1.0;
        }

        $types = array_map(
            static fn(array $row): string => (string)(($row['payload'] ?? $row)['type'] ?? ''),
            $store->recent(self::BRACELET, 'telemetry'),
        );
        self::assertNotContains('proximity', $types, 'a uns 40 avistamentos por minuto, isto enterrava o histórico');
        self::assertContains('battery', $types, 'a outra telemetria continua a ser guardada');
    }

    public function testAQuietPairIsReportedAsUnknownOnce(): void
    {
        [$bridge, $mqtt] = $this->bridge();
        $this->deliver($bridge, $this->scanPayload(['rssi' => -52]));

        $bridge->expireStaleProximity();
        self::assertCount(1, $this->proximity($mqtt->telemetry), 'nada a reportar enquanto ainda se ouve');

        $this->now += 31.0;
        $bridge->expireStaleProximity();
        $bridge->expireStaleProximity();

        $proximity = $this->proximity($mqtt->telemetry);
        self::assertCount(2, $proximity, 'o silêncio reporta-se uma vez, e não a cada tique');
        $unknown = end($proximity)['payload']['data'];
        self::assertSame('unknown', $unknown['state']);
        self::assertSame(0, $unknown['samples']);
        self::assertSame(self::GATEWAY, $unknown['gatewayId']);
        // O desconhecido não pode passar por leitura, e por isso não leva sinal nenhum.
        self::assertArrayNotHasKey('rssiMedianDbm', $unknown);
    }

    /** A manutenção corre uma vez por janela de cinco segundos, e não a cada tique. */
    public function testMaintenanceIsThrottledToOncePerWindow(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = new MokoBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::gateway('MKGW3'),
                self::BRACELET => IngressFixtures::bracelet('W6B'),
            ]),
            $mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
            deviceStore: new DeviceStore(new InMemoryRedisClient()),
            clock: fn(): float => $this->now,
        );

        // Ouvido às 1000 s, o par fica velho aos 30 s; a manutenção das 1029 s abre a janela.
        $this->deliver($bridge, $this->scanPayload(['rssi' => -52]));
        $this->now = 1029.0;
        $bridge->runDueMaintenance();

        $this->now = 1031.0;
        $bridge->runDueMaintenance();
        self::assertSame(0, $this->unknownCount($mqtt), 'dentro da janela, a manutenção não corre outra vez');

        $this->now = 1034.0;
        $bridge->runDueMaintenance();
        self::assertSame(1, $this->unknownCount($mqtt), 'passada a janela, a manutenção corre');
    }

    private function unknownCount(RecordingHubMqttBridge $mqtt): int
    {
        return count(array_filter(
            $this->proximity($mqtt->telemetry),
            static fn (array $p): bool => ($p['payload']['data']['state'] ?? '') === 'unknown',
        ));
    }
}
