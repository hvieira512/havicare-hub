<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Gateway;

use Hub\Ingress\Mqtt\JoyTech\JoyTechBpBridge;
use Hub\Ingress\Mqtt\JoyTech\JoyTechThermometerBridge;
use Hub\Ingress\Mqtt\Lefu\LefuTorreBridge;
use Hub\Ingress\Mqtt\MqttBridgeBase;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use Hub\Registry\Denylist;
use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\FakeMqttSubscriber;
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
            'pulseira' => ['veepoo', 'veepoo-node', 'MF91'],
            'balança' => ['lefu', 'lefu-torre-node', 'CF632'],
            'tensiómetro' => ['joytech-bp', 'joytech-node', 'DBP-6292B'],
            'termómetro' => ['joytech-thermometer', 'joytech-node', 'DET-3018'],
        ];
    }

    /**
     * @dataProvider bridges
     * @param 'veepoo'|'lefu'|'joytech-bp'|'joytech-thermometer' $bridge
     */
    public function testABlockedDeviceProducesNoNotification(string $bridge, string $source, string $model): void
    {
        $store = $this->createMock(DeviceStoreContract::class);
        $store->expects(self::never())->method('recordRejectedDevice');
        $denylist = new Denylist();
        $denylist->block(self::STRANGER);

        self::bridge($bridge, $store, $denylist)->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => $source,
            'kind' => 'session',
            'device' => ['mac' => self::STRANGER, 'model' => $model],
            'payload' => ['connected' => true],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param 'veepoo'|'lefu'|'joytech-bp'|'joytech-thermometer' $name */
    private static function bridge(string $name, DeviceStoreContract $store, Denylist $denylist): MqttBridgeBase
    {
        $subscriber = new FakeMqttSubscriber();
        $whitelist = IngressFixtures::whitelist([
            self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
        ]);
        $mqtt = new RecordingHubMqttBridge();
        $links = IngressFixtures::links();
        $topic = 'havicare-hub/null/0/gw/+/raw';

        return match ($name) {
            'veepoo' => new VeepooBridge($subscriber, $whitelist, $mqtt, $links, null, new ArrayObservationStateStore(), $topic, null, $store, denylist: $denylist),
            'lefu' => new LefuTorreBridge($subscriber, $whitelist, $mqtt, $links, null, $topic, null, $store, denylist: $denylist),
            'joytech-bp' => new JoyTechBpBridge($subscriber, $whitelist, $mqtt, $links, null, $topic, null, $store, denylist: $denylist),
            'joytech-thermometer' => new JoyTechThermometerBridge($subscriber, $whitelist, $mqtt, $links, $topic, null, $store, denylist: $denylist),
        };
    }
}
