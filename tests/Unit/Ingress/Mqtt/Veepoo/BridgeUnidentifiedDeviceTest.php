<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Ingress\Mqtt\Gateway\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Veepoo\Bridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * Uma trama sem aparelho não é um aparelho por autorizar.
 *
 * O gateway só sabe com que pulseira está a falar depois de ela se autenticar, e uma trama
 * que chegue antes disso -- ou logo depois de a ligação cair -- vem sem MAC. O hub registava
 * isso como «dispositivo não autorizado» com identidade vazia: uma notificação no sino, com
 * um botão «Registar» que não podia funcionar porque não havia o que registar.
 *
 * Oito delas em cinco horas, numa manhã de reinícios do gateway.
 */
final class BridgeUnidentifiedDeviceTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** @return array<string, array{array<string, mixed>|null}> */
    public static function unidentified(): array
    {
        return [
            'sem aparelho nenhum' => [null],
            'com aparelho sem MAC' => [['model' => 'MF91']],
            'com o MAC vazio' => [['mac' => '']],
        ];
    }

    /**
     * @dataProvider unidentified
     *
     * @param array<string, mixed>|null $device
     */
    public function testAFrameWithoutADeviceIsNotRecordedAsUnauthorised(?array $device): void
    {
        $recorded = [];
        $store = $this->createMock(DashboardStoreContract::class);
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
        $store = $this->createMock(DashboardStoreContract::class);
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

    private function bridge(DashboardStoreContract $store): Bridge
    {
        return new Bridge(
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
