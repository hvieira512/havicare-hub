<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\State\DeviceStoreContract;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Moko\MokoBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * Em repouso a W6 anuncia a trama do acelerómetro (`bxp-acc`); um toque liga durante trinta
 * segundos o slot Eddystone-UID com o instance id desse modo, sem contador cumulativo.
 */
final class BridgeW6Test extends TestCase
{
    private const GATEWAY = 'c5e390f30bce';
    private const BRACELET = 'fa05c2c70fc6';

    private function accPayload(): string
    {
        return $this->payload([
            'type_code' => 5,
            'type' => 'bxp-acc',
            'rssi' => -33,
            'mac' => self::BRACELET,
            'x_axis_data' => -956,
            'y_axis_data' => 272,
            'z_axis_data' => 140,
            'batt_vol' => 2808,
        ]);
    }

    private function pressPayload(string $instance): string
    {
        return $this->payload([
            'type_code' => 1,
            'type' => 'eddystone-uid',
            'rssi' => -44,
            'mac' => self::BRACELET,
            'namespace' => '00000000fa05c2c70fc6',
            'instance' => $instance,
        ]);
    }

    /** @param array<string, mixed> $observation */
    private function payload(array $observation): string
    {
        return json_encode([
            'msg_id' => 3070,
            'device_info' => ['mac' => self::GATEWAY],
            'data' => [$observation],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, array<string, string>> $extraDevices
     */
    private function bridge(
        ?DeviceStoreContract $deviceStore = null,
        ?RecordingHubMqttBridge $mqtt = null,
        array $extraDevices = [],
    ): MokoBridge {
        return new MokoBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::gateway('MKGW4'),
            ] + $extraDevices),
            $mqtt ?? new RecordingHubMqttBridge(),
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
            deviceStore: $deviceStore,
        );
    }

    /** @return array<string, array<string, string>> */
    private function registered(): array
    {
        return [
            self::BRACELET => IngressFixtures::bracelet('W6'),
        ];
    }

    private function deliver(MokoBridge $bridge, string $payload): void
    {
        $bridge->handleReceivedMessage('havicare-hub/null/0/gw/' . self::GATEWAY . '/raw', $payload);
    }

    /**
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

    public function testAnUnregisteredW6BaisesADashboardNotification(): void
    {
        $deviceStore = $this->createMock(DeviceStoreContract::class);
        $deviceStore->expects(self::once())
            ->method('recordRejectedDevice')
            ->with(self::BRACELET, 'moko-w6', 'W6', self::BRACELET, 'device_not_authorized', 0);

        $this->deliver($this->bridge($deviceStore), $this->accPayload());
    }

    public function testARegisteredW6IsSeenInsteadOfRejected(): void
    {
        // O gateway também se anuncia a si próprio, por isso guardam-se todas as chamadas
        // e olha-se só para a da pulseira.
        $seen = [];
        $deviceStore = $this->createMock(DeviceStoreContract::class);
        $deviceStore->expects(self::never())->method('recordRejectedDevice');
        $deviceStore->method('deviceSeen')
            ->willReturnCallback(function (string $imei, array $state) use (&$seen): bool {
                $seen[$imei] = $state;
                return false;
            });

        $this->deliver(
            $this->bridge($deviceStore, extraDevices: $this->registered()),
            $this->accPayload(),
        );

        self::assertArrayHasKey(self::BRACELET, $seen);
        self::assertSame('moko-w6', $seen[self::BRACELET]['protocol']);
        self::assertSame('bracelet', $seen[self::BRACELET]['deviceType']);
        self::assertSame('1', $seen[self::BRACELET]['online']);
    }

    public function testTheAccelerometerFramePublishesBatteryAndMotion(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->deliver(
            $this->bridge(null, $mqtt, $this->registered()),
            $this->accPayload(),
        );

        $types = array_column($this->forBracelet($mqtt->telemetry), 'type');
        self::assertContains('battery', $types);
        self::assertContains('motion', $types);
    }

    public function testEachPressModePublishesItsOwnHelpCall(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge(null, $mqtt, $this->registered());

        $this->deliver($bridge, $this->pressPayload('000000000011'));
        $this->deliver($bridge, $this->pressPayload('000000000012'));
        $this->deliver($bridge, $this->pressPayload('000000000013'));

        $events = $this->forBracelet($mqtt->events);
        self::assertSame(['help_call', 'help_call', 'help_call'], array_column($events, 'type'));
        self::assertSame(
            ['single', 'double', 'triple'],
            array_map(static fn(array $e): string => $e['payload']['data']['pressType'], $events),
        );
        self::assertSame(self::GATEWAY, $events[0]['payload']['source']['gatewayId']);
    }

    /**
     * O slot anuncia-se durante trinta segundos e cada gateway ao alcance repete-o: um toque
     * não pode virar trinta pedidos de ajuda.
     */
    public function testTheRepeatedFrameOfOnePressRaisesOneHelpCall(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge(null, $mqtt, $this->registered());

        for ($i = 0; $i < 5; $i++) {
            $this->deliver($bridge, $this->pressPayload('000000000011'));
        }

        self::assertCount(1, $this->forBracelet($mqtt->events));
    }

    /** O slot de identidade anuncia-se em permanência, e nunca pode ler-se como um toque. */
    public function testTheIdentitySlotRaisesNoHelpCall(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->deliver(
            $this->bridge(null, $mqtt, $this->registered()),
            $this->pressPayload('000000000001'),
        );

        self::assertSame([], $this->forBracelet($mqtt->events));
    }
}
