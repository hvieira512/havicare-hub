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
