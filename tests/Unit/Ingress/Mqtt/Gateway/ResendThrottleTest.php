<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Gateway;

use Hub\Ingress\Mqtt\Gateway\GattDownlinks;
use Hub\Ingress\Mqtt\Veepoo\DownlinkDispatcher;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\OneShotDownlinkQueue;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** A mesma ordem a dois aparelhos tem a mesma chave; o travão de reenvio é de cada um. */
final class ResendThrottleTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const TOPIC = 'havicare-hub/null/0/gw/+/raw';

    public function testTheSecondBraceletGetsTheSameCommandAtOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $dispatcher = new DownlinkDispatcher(new OneShotDownlinkQueue('config:find_device'), $mqtt, null, self::TOPIC);

        $dispatcher->dispatchPending('9f69c4866e6c', self::GATEWAY);
        $dispatcher->dispatchPending('3a8dda1bc715', self::GATEWAY);

        self::assertSame(['9f69c4866e6c', '3a8dda1bc715'], self::recipients($mqtt));
    }

    public function testTheSecondGattDeviceGetsTheSameCommandAtOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $downlinks = new GattDownlinks(
            new OneShotDownlinkQueue('calibrateClock'),
            $mqtt,
            null,
            self::TOPIC,
            static fn (): array => [['char' => 'fff2', 'hex' => '00']],
        );

        $downlinks->dispatchPending('748b34837b1e', self::GATEWAY, 23, 0);
        $downlinks->dispatchPending('748b3483cda4', self::GATEWAY, 23, 0);

        self::assertSame(['748b34837b1e', '748b3483cda4'], self::recipients($mqtt));
    }

    /** @return list<string> */
    private static function recipients(RecordingHubMqttBridge $mqtt): array
    {
        return array_map(static fn (array $c): string => (string)$c['payload']['deviceId'], $mqtt->gatewayCommands);
    }
}
