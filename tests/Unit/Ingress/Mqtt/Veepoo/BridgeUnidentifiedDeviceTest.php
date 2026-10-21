<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Uma trama sem MAC, de pulseira por autenticar ou de ligação acabada de cair, não tem aparelho a registar. */
final class BridgeUnidentifiedDeviceTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** @return array<string, array{array<string, mixed>|null}> */
    public static function unidentified(): array
    {
        return [
            'no device at all' => [null],
            'a device without a MAC' => [['model' => 'MF91']],
            'an empty MAC' => [['mac' => '']],
        ];
    }

    /** @param array<string, mixed>|null $device */
    #[DataProvider('unidentified')]
    public function testAFrameWithoutADeviceIsNotRecordedAsUnauthorised(?array $device): void
    {
        $recorded = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('recordRejectedDevice')->willReturnCallback(
            static function (string $imei) use (&$recorded): void {
                $recorded[] = $imei;
            }
        );

        $this->bridge($store)->handleReceivedMessage(self::TOPIC, json_encode(array_filter([
            'source' => 'veepoo-node',
            'kind' => 'battery',
            'device' => $device,
            'payload' => ['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 57],
        ], static fn(mixed $v): bool => $v !== null), JSON_THROW_ON_ERROR));

        self::assertSame([], $recorded);
    }

    /** Um aparelho identificado e por registar continua a dar notificação -- essa é útil. */
    public function testAnIdentifiedStrangerIsStillRecorded(): void
    {
        $recorded = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('recordRejectedDevice')->willReturnCallback(
            static function (string $imei) use (&$recorded): void {
                $recorded[] = $imei;
            }
        );

        $this->bridge($store)->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'battery',
            'device' => ['mac' => 'aabbccddeeff'],
            'payload' => ['VPDeviceIsPercent' => true, 'VPDeviceElectricPercent' => 57],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['aabbccddeeff'], $recorded);
    }

    private function bridge(DeviceStoreContract $store): VeepooBridge
    {
        return new VeepooBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
            ]),
            new RecordingHubMqttBridge(),
            IngressFixtures::links(true),
            null,
            new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
            null,
            $store,
        );
    }
}
