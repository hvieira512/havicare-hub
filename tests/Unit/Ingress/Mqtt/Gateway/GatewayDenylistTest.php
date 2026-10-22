<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Gateway;

use Hub\Ingress\Mqtt\Gateway\GatewayRelay;
use Hub\Ingress\Mqtt\JoyTech\JoyTechBpBridge;
use Hub\Ingress\Mqtt\JoyTech\JoyTechThermometerBridge;
use Hub\Ingress\Mqtt\Lefu\LefuTorreBridge;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use Hub\Registry\Denylist;
use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Um aparelho bloqueado atrás do gateway BLE não dá notificação, como um radar bloqueado. */
final class GatewayDenylistTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const STRANGER = 'aabbccddeeff';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** @return array<string, array{'veepoo'|'lefu'|'joytech-bp'|'joytech-thermometer', string, string}> */
    public static function bridges(): array
    {
        return [
            'bracelet' => ['veepoo', 'veepoo-node', 'MF91'],
            'scale' => ['lefu', 'lefu-torre-node', 'CF632'],
            'blood pressure monitor' => ['joytech-bp', 'joytech-node', 'DBP-6292B'],
            'thermometer' => ['joytech-thermometer', 'joytech-node', 'DET-3018'],
        ];
    }

    /** @param 'veepoo'|'lefu'|'joytech-bp'|'joytech-thermometer' $bridge */
    #[DataProvider('bridges')]
    public function testABlockedDeviceProducesNoNotification(string $bridge, string $source, string $model): void
    {
        $store = $this->createMock(DeviceStoreContract::class);
        $store->expects(self::never())->method('recordRejectedDevice');
        $denylist = new Denylist();
        $denylist->block(self::STRANGER);

        IngressFixtures::relay(self::bridge($bridge, $store, $denylist), self::TOPIC, json_encode([
            'source' => $source,
            'kind' => 'session',
            'device' => ['mac' => self::STRANGER, 'model' => $model],
            'payload' => ['connected' => true],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * O sinal com que o gateway ouve o aparelho é o da ligação entre os dois, como o de um MOKO.
     *
     * @param 'veepoo'|'lefu'|'joytech-bp'|'joytech-thermometer' $bridge
     */
    #[DataProvider('bridges')]
    public function testTheSignalTheGatewayReportsIsKeptForTheLink(string $bridge, string $source, string $model): void
    {
        $sightings = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('recordGatewaySighting')->willReturnCallback(
            static function (string $device, string $gateway, ?int $rssiDbm) use (&$sightings): void {
                $sightings[] = [$device, $gateway, $rssiDbm];
            }
        );
        $whitelist = [self::STRANGER => IngressFixtures::device('X', $model, match ($bridge) {
            'veepoo' => 'bracelet',
            'lefu' => 'scale',
            'joytech-bp' => 'blood_pressure_monitor',
            'joytech-thermometer' => 'thermometer',
        })];

        IngressFixtures::relay(self::bridge($bridge, $store, new Denylist(), $whitelist), self::TOPIC, json_encode([
            'source' => $source,
            'kind' => 'session',
            'device' => ['mac' => self::STRANGER, 'model' => $model, 'rssiDbm' => -63],
            'payload' => ['connected' => true, 'authenticated' => true],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([[self::STRANGER, self::GATEWAY, -63]], $sightings);
    }

    /**
     * @param 'veepoo'|'lefu'|'joytech-bp'|'joytech-thermometer' $name
     * @param array<string, array<string, mixed>> $devices
     */
    private static function bridge(string $name, DeviceStoreContract $store, Denylist $denylist, array $devices = []): GatewayRelay
    {
        $whitelist = IngressFixtures::whitelist([
            self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
        ] + $devices);
        $mqtt = new RecordingHubMqttBridge();
        $links = IngressFixtures::links();
        $topic = 'havicare-hub/null/0/gw/+/raw';

        return match ($name) {
            'veepoo' => new VeepooBridge($whitelist, $mqtt, $links, null, new ArrayObservationStateStore(), $topic, $store, denylist: $denylist),
            'lefu' => new LefuTorreBridge($whitelist, $mqtt, $links, null, $topic, $store, denylist: $denylist),
            'joytech-bp' => new JoyTechBpBridge($whitelist, $mqtt, $links, null, $topic, $store, denylist: $denylist),
            'joytech-thermometer' => new JoyTechThermometerBridge($whitelist, $mqtt, $links, $topic, $store, denylist: $denylist),
        };
    }
}
