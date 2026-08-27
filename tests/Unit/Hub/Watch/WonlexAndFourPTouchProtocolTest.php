<?php

declare(strict_types=1);

namespace Tests\Unit\Hub\Watch;

use Hub\DeviceEventDecoder;
use Hub\DeviceSession;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Protocol\Adapter\WonlexAdapter;
use Hub\Watch\Supplier\FourPTouch\FourPTouchWatchProtocol;
use Hub\Watch\Supplier\Wonlex\WonlexWatchProtocol;
use PHPUnit\Framework\TestCase;

final class WonlexAndFourPTouchProtocolTest extends TestCase
{
    public function testWonlexLoginAndHeartbeatBuildResponses(): void
    {
        $protocol = new WonlexWatchProtocol(new WonlexAdapter(), new DeviceEventDecoder());
        $session = new DeviceSession(new WatchFakeConnection(), 'tcp', true, '868705080300697', 'wonlex-json');

        $login = $protocol->handleIncoming($session, $this->wonlexFrame(['type' => 'login', 'ident' => 614377, 'imei' => '868705080300697']));
        $heartbeat = $protocol->handleIncoming($session, $this->wonlexFrame(['type' => 'heartbeat', 'ident' => 614377, 'imei' => '868705080300697', 'data' => ['batteryLevel' => 90]]));

        self::assertNotNull($login);
        self::assertNotNull($heartbeat);
        self::assertSame('login', (new WonlexAdapter())->decodeIncoming($login->responses[0]->bytes)['type']);
        self::assertSame('heartbeat', (new WonlexAdapter())->decodeIncoming($heartbeat->responses[0]->bytes)['type']);
    }

    /**
     * Um dispositivo precisa de empresa E de licença para contar como ligado. Só com a
     * verificação da empresa, um `licenseId` comparado contra o tipo errado passava
     * despercebido.
     */
    public function testWonlexLoginIsUnboundWhenACompanyHasNoLicense(): void
    {
        $protocol = new WonlexWatchProtocol(new WonlexAdapter(), new DeviceEventDecoder());
        $session = new DeviceSession(
            new WatchFakeConnection(),
            'tcp',
            true,
            '868705080300697',
            'wonlex-json',
            'Wonlex',
            'HW20PRO',
            '',
            'watch',
            0,
            'havicare'
        );

        $reply = $protocol->handleIncoming($session, $this->wonlexFrame(['type' => 'login', 'ident' => 100003]));

        self::assertSame(
            0,
            (new WonlexAdapter())->decodeIncoming($reply->responses[0]->bytes)['data']['bindStatus']
        );
    }

    public function testWonlexLoginUsesActualBindingState(): void
    {
        $protocol = new WonlexWatchProtocol(new WonlexAdapter(), new DeviceEventDecoder());
        $unbound = new DeviceSession(new WatchFakeConnection(), 'tcp', true, '868705080300697', 'wonlex-json');
        $bound = new DeviceSession(
            new WatchFakeConnection(),
            'tcp',
            true,
            '868705080300697',
            'wonlex-json',
            'Wonlex',
            'HW20PRO',
            '',
            'watch',
            1001,
            'hitcare'
        );

        $unboundReply = $protocol->handleIncoming($unbound, $this->wonlexFrame(['type' => 'login', 'ident' => 100001]));
        $boundReply = $protocol->handleIncoming($bound, $this->wonlexFrame(['type' => 'login', 'ident' => 100002]));
        $adapter = new WonlexAdapter();

        self::assertSame(0, $adapter->decodeIncoming($unboundReply->responses[0]->bytes)['data']['bindStatus']);
        self::assertSame(1, $adapter->decodeIncoming($boundReply->responses[0]->bytes)['data']['bindStatus']);
    }

    public function testWonlexDeviceRequestsReceiveSpecificDownlinksWithSameIdent(): void
    {
        $protocol = new WonlexWatchProtocol(
            new WonlexAdapter(),
            new DeviceEventDecoder(),
            static fn(): array => [
                'bindStatus' => 1,
                'configurations' => [
                    ['command' => 'locationInterval', 'payload' => ['intervalTime' => 300]],
                    ['command' => 'deviceMeasuringFrequency', 'payload' => ['configs' => ['upHeartRate' => ['interval' => '60']]]],
                    ['command' => 'deviceConfig', 'payload' => ['configs' => ['StepTarget' => ['steps' => 6000]]]],
                ],
                'sleep' => [
                    'segments' => [
                        ['type' => 'deepSleep', 'durationMinutes' => 48],
                        ['type' => 'lightSleep', 'durationMinutes' => 152],
                    ],
                ],
            ]
        );
        $session = new DeviceSession(new WatchFakeConnection(), 'tcp', true, '868705080300697', 'wonlex-json');
        $adapter = new WonlexAdapter();

        $binding = $protocol->handleIncoming($session, $this->wonlexFrame([
            'type' => 'upGetDevBindStatus', 'ident' => 123456, 'ref' => 'w:update',
        ]));
        self::assertSame('dnDevBindStatus', $adapter->decodeIncoming($binding->responses[0]->bytes)['type']);
        self::assertSame(123456, $adapter->decodeIncoming($binding->responses[0]->bytes)['ident']);

        $config = $protocol->handleIncoming($session, $this->wonlexFrame([
            'type' => 'upGetDevConfig', 'ident' => 234567, 'ref' => 'w:update',
        ]));
        self::assertSame(
            ['locationInterval', 'deviceMeasuringFrequency', 'deviceConfig'],
            array_map(static fn($response): string => $adapter->decodeIncoming($response->bytes)['type'], $config->responses)
        );
        self::assertSame(300, $adapter->decodeIncoming($config->responses[0]->bytes)['data']['intervalTime']);

        $sleep = $protocol->handleIncoming($session, $this->wonlexFrame([
            'type' => 'upSleepFind', 'ident' => 345678, 'ref' => 'w:update', 'data' => ['upDayStr' => '2026-07-27'],
        ]));
        self::assertSame('200/48/152/0', $adapter->decodeIncoming($sleep->responses[0]->bytes)['data']['value']);

    }

    public function testFourPTouchProducesProtocolAck(): void
    {
        $protocol = new FourPTouchWatchProtocol(new FourPTouchAdapter(), new DeviceEventDecoder());
        $session = new DeviceSession(new WatchFakeConnection(), 'tcp', true, '637507597567372', 'four-p-touch');

        $message = $protocol->handleIncoming($session, '[3G*7597567372*000D*LK,50,100,100]');

        self::assertNotNull($message);
        self::assertCount(1, $message->responses);
        self::assertSame('[3G*7597567372*0002*LK]', $message->responses[0]->bytes);
    }

    public function testFourPTouchFirmwareVersionDoesNotProduceProtocolAck(): void
    {
        $protocol = new FourPTouchWatchProtocol(new FourPTouchAdapter(), new DeviceEventDecoder());
        $session = new DeviceSession(new WatchFakeConnection(), 'tcp', true, '637507597567372', 'four-p-touch');

        $message = $protocol->handleIncoming($session, '[3G*7597567372*000C*VERNO,ABC123]');

        self::assertNotNull($message);
        self::assertCount(0, $message->responses);
    }

    public function testFourPTouchDeviceStatusDoesNotProduceProtocolAck(): void
    {
        $protocol = new FourPTouchWatchProtocol(new FourPTouchAdapter(), new DeviceEventDecoder());
        $session = new DeviceSession(new WatchFakeConnection(), 'tcp', true, '637507597567372', 'four-p-touch');

        $message = $protocol->handleIncoming($session, '[3G*7597567372*0002*TS]');

        self::assertNotNull($message);
        self::assertSame('TS', $message->decoded['type']);
        self::assertCount(0, $message->responses);
    }

    private function wonlexFrame(array $payload): string
    {
        return (new WonlexAdapter())->encodeOutgoing($payload);
    }
}

final class WatchFakeConnection implements \Hub\ConnectionInterface
{
    public int $resourceId = 1;

    public function send($data): static
    {
        return $this;
    }

    public function close(): static
    {
        return $this;
    }
}
