<?php

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Dashboard\DashboardStore;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\Moko\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Moko\Bridge;
use Hub\Ingress\Mqtt\Moko\ProximityTracker;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * Signal reporting for a relayed device: one message per sighting per gateway.
 *
 * O contrato que estes testes prendem está em `docs/proximity-alarms.md`: o hub reporta o
 * sinal e diz quando ele se calou; os limiares e os alarmes são do cliente.
 */
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

    /** @return array{0: Bridge, 1: RecordingHubMqttBridge, 2: DashboardStore} */
    private function bridge(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'moko-proximity-whitelist-');
        file_put_contents($path, json_encode([
            self::GATEWAY => ['supplier' => 'MOKO', 'model' => 'MKGW3', 'deviceType' => 'gateway', 'licenseId' => '1001', 'company' => 'hitcare'],
            self::GATEWAY2 => ['supplier' => 'MOKO', 'model' => 'MKGW4', 'deviceType' => 'gateway', 'licenseId' => '1001', 'company' => 'hitcare'],
            self::BRACELET => ['supplier' => 'MOKO', 'model' => 'W6B', 'deviceType' => 'bracelet', 'licenseId' => '1001', 'company' => 'hitcare'],
        ], JSON_THROW_ON_ERROR));

        $links = new class implements GatewayDeviceLinkLookup {
            public function isEnabled(string $gatewayDeviceKey, string $linkedDeviceKey): bool
            {
                return true;
            }
        };
        $mqtt = new RecordingHubMqttBridge();
        $store = new DashboardStore(new InMemoryRedisClient(), prefix: 'test:dashboard:proximity');

        $bridge = new Bridge(
            new FakeMqttSubscriber(),
            new Whitelist($path),
            $mqtt,
            $links,
            new ArrayObservationStateStore(),
            dashboardStore: $store,
            clock: fn(): float => $this->now,
            proximityTracker: new ProximityTracker(windowSeconds: 5, maxSamples: 10, stalenessSeconds: 30),
        );

        return [$bridge, $mqtt, $store];
    }

    private function deliver(Bridge $bridge, string $payload, string $gateway = self::GATEWAY): void
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

        // O mesmo payload três vezes: a bateria e o movimento não mudam, e por isso o
        // estrangulamento por impressão digital suprime-os depois do primeiro. O sinal tem de
        // ser reportado todas as vezes, senão a série do cliente fica com buracos que ele não
        // vê e qualquer estatística que calcule está errada em silêncio.
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
        self::assertCount(1, $throttled, 'battery is unchanged, so it stays throttled');
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
        self::assertSame(self::GATEWAY, $data['gatewayId'], 'the pair must be named in data, not only in source');
        self::assertSame('measured', $data['state']);
        self::assertSame(-52, $data['rssiDbm']);
        self::assertSame(-52, $data['rssiMaxDbm'], 'the pass is only visible in the maximum');
        self::assertSame(-77, $data['rssiMedianDbm'], 'and must not move the median');
        self::assertSame(-78, $data['rssiMinDbm']);
        self::assertSame(4, $data['samples']);
        self::assertSame(5, $data['windowSeconds']);
    }

    public function testEachGatewayReportsItsOwnSignalForTheSameDevice(): void
    {
        [$bridge, $mqtt] = $this->bridge();

        $this->deliver($bridge, $this->scanPayload(['rssi' => -85], self::GATEWAY), self::GATEWAY);
        $this->deliver($bridge, $this->scanPayload(['rssi' => -52], self::GATEWAY2), self::GATEWAY2);

        $proximity = $this->proximity($mqtt->telemetry);
        self::assertSame(
            [[self::GATEWAY, -85], [self::GATEWAY2, -52]],
            array_map(
                static fn(array $e): array => [$e['payload']['data']['gatewayId'], $e['payload']['data']['rssiMaxDbm']],
                $proximity,
            ),
        );
        // One door's window must never be fed by another's.
        self::assertSame(1, $proximity[1]['payload']['data']['samples']);
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
        self::assertNotContains('proximity', $types, 'at ~40 sightings a minute this would bury the history');
        self::assertContains('battery', $types, 'the other telemetry is still recorded');
    }

    public function testAQuietPairIsReportedAsUnknownOnce(): void
    {
        [$bridge, $mqtt] = $this->bridge();
        $this->deliver($bridge, $this->scanPayload(['rssi' => -52]));

        $bridge->expireStaleProximity();
        self::assertCount(1, $this->proximity($mqtt->telemetry), 'nothing to report while it is still being heard');

        $this->now += 31.0;
        $bridge->expireStaleProximity();
        $bridge->expireStaleProximity();

        $proximity = $this->proximity($mqtt->telemetry);
        self::assertCount(2, $proximity, 'silence is reported once, not on every tick');
        $unknown = end($proximity)['payload']['data'];
        self::assertSame('unknown', $unknown['state']);
        self::assertSame(0, $unknown['samples']);
        self::assertSame(self::GATEWAY, $unknown['gatewayId']);
        // O desconhecido não pode passar por leitura, e por isso não leva sinal nenhum.
        self::assertArrayNotHasKey('rssiMedianDbm', $unknown);
    }

    public function testNothingIsReportedForADeviceTheGatewayMayNotRelay(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'moko-proximity-unlinked-');
        file_put_contents($path, json_encode([
            self::GATEWAY => ['supplier' => 'MOKO', 'model' => 'MKGW3', 'deviceType' => 'gateway', 'licenseId' => '1001', 'company' => 'hitcare'],
            self::BRACELET => ['supplier' => 'MOKO', 'model' => 'W6B', 'deviceType' => 'bracelet', 'licenseId' => '1001', 'company' => 'hitcare'],
        ], JSON_THROW_ON_ERROR));
        $mqtt = new RecordingHubMqttBridge();
        $bridge = new Bridge(
            new FakeMqttSubscriber(),
            new Whitelist($path),
            $mqtt,
            new class implements GatewayDeviceLinkLookup {
                public function isEnabled(string $gatewayDeviceKey, string $linkedDeviceKey): bool
                {
                    return false;
                }
            },
            new ArrayObservationStateStore(),
            clock: fn(): float => $this->now,
        );

        $this->deliver($bridge, $this->scanPayload(['rssi' => -52]));

        self::assertSame([], $this->proximity($mqtt->telemetry));
    }
}
