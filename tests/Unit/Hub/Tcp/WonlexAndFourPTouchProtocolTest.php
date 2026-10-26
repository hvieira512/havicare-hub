<?php

declare(strict_types=1);

namespace Tests\Unit\Hub\Tcp;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Ingress\Tcp\Supplier\FourPTouch\FourPTouchTcpProtocol;
use Hub\Ingress\Tcp\Supplier\Wonlex\WonlexTcpProtocol;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Protocol\Adapter\WonlexAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeConnection;

final class WonlexAndFourPTouchProtocolTest extends TestCase
{
    public function testWonlexLoginAndHeartbeatBuildResponses(): void
    {
        $protocol = new WonlexTcpProtocol(new WonlexAdapter(), new DeviceEventDecoder());
        $session = new DeviceSession(new FakeConnection(), true, '868705080300697', 'wonlex-json');

        $login = $protocol->handleIncoming($session, $this->wonlexFrame(['type' => 'login', 'ident' => 614377, 'imei' => '868705080300697']));
        $heartbeat = $protocol->handleIncoming($session, $this->wonlexFrame(['type' => 'heartbeat', 'ident' => 614377, 'imei' => '868705080300697', 'data' => ['batteryLevel' => 90]]));

        self::assertNotNull($login);
        self::assertNotNull($heartbeat);
        self::assertSame('login', (new WonlexAdapter())->decodeIncoming($login->responses[0]->bytes)['type']);
        self::assertSame('heartbeat', (new WonlexAdapter())->decodeIncoming($heartbeat->responses[0]->bytes)['type']);
    }

    /** O estado custa uma consulta à base: só o lêem as tramas que respondem com ele. */
    public function testAnOrdinaryUpdateDoesNotReadTheDeviceState(): void
    {
        $reads = 0;
        $protocol = new WonlexTcpProtocol(
            new WonlexAdapter(),
            new DeviceEventDecoder(),
            static function () use (&$reads): array {
                $reads++;
                return ['configurations' => []];
            },
        );
        $session = new DeviceSession(new FakeConnection(), true, '868705080300697', 'wonlex-json');

        $protocol->handleIncoming($session, $this->wonlexFrame(['type' => 'upHeartRate', 'ident' => 1, 'ref' => 'w:update', 'imei' => '868705080300697', 'data' => ['heartRate' => 70]]));

        self::assertSame(0, $reads);
    }

    public function testWonlexDeviceRequestsReceiveSpecificDownlinksWithSameIdent(): void
    {
        $protocol = new WonlexTcpProtocol(
            new WonlexAdapter(),
            new DeviceEventDecoder(),
            static fn(): array => [
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
        $session = new DeviceSession(new FakeConnection(), true, '868705080300697', 'wonlex-json');
        $adapter = new WonlexAdapter();

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
        $protocol = new FourPTouchTcpProtocol(new FourPTouchAdapter(), new DeviceEventDecoder());
        $session = new DeviceSession(new FakeConnection(), true, '637507597567372', 'four-p-touch');

        $message = $protocol->handleIncoming($session, '[3G*7597567372*000D*LK,50,100,100]');

        self::assertNotNull($message);
        self::assertCount(1, $message->responses);
        self::assertSame('[3G*7597567372*0002*LK]', $message->responses[0]->bytes);
    }

    public function testFourPTouchAlarmProducesProtocolAck(): void
    {
        $protocol = new FourPTouchTcpProtocol(new FourPTouchAdapter(), new DeviceEventDecoder());
        $session = new DeviceSession(new FakeConnection(), true, '637507597567372', 'four-p-touch');
        $alarm = (new FourPTouchAdapter())->encodeOutgoing([
            'type' => 'AL',
            'imei' => '7597567372',
            'manufacturer' => '3G',
            'data' => ['fields' => ['240617', '101530', 'V', '0.0', 'N', '0.0', 'E', '0.0', '0', '0', '0', '55', '44', '0', '0', '00200000']],
        ]);

        $message = $protocol->handleIncoming($session, $alarm);

        self::assertNotNull($message);
        self::assertSame(['[3G*7597567372*0002*AL]'], array_map(static fn($response): string => $response->bytes, $message->responses));
    }

    public function testFourPTouchFirmwareVersionDoesNotProduceProtocolAck(): void
    {
        $protocol = new FourPTouchTcpProtocol(new FourPTouchAdapter(), new DeviceEventDecoder());
        $session = new DeviceSession(new FakeConnection(), true, '637507597567372', 'four-p-touch');

        $message = $protocol->handleIncoming($session, '[3G*7597567372*000C*VERNO,ABC123]');

        self::assertNotNull($message);
        self::assertCount(0, $message->responses);
    }

    public function testFourPTouchDeviceStatusDoesNotProduceProtocolAck(): void
    {
        $protocol = new FourPTouchTcpProtocol(new FourPTouchAdapter(), new DeviceEventDecoder());
        $session = new DeviceSession(new FakeConnection(), true, '637507597567372', 'four-p-touch');

        $message = $protocol->handleIncoming($session, '[3G*7597567372*0002*TS]');

        self::assertNotNull($message);
        self::assertSame('TS', $message->decoded['type']);
        self::assertCount(0, $message->responses);
    }

    public function testWonlexCommandMetadataParsesTheDownlinkBytes(): void
    {
        $protocol = new WonlexTcpProtocol(new WonlexAdapter(), new DeviceEventDecoder());

        self::assertSame([
            'nativeType' => 'locationInterval',
            'protocol' => 'wonlex-json',
            'ident' => 234567,
        ], $protocol->commandMetadata($this->wonlexFrame([
            'type' => 'locationInterval',
            'ident' => 234567,
            'data' => ['intervalTime' => 300],
        ])));
    }

    public function testWonlexCommandMetadataRejectsBytesItCannotDecode(): void
    {
        $protocol = new WonlexTcpProtocol(new WonlexAdapter(), new DeviceEventDecoder());

        self::assertNull($protocol->commandMetadata('nada disto é uma trama'));
    }

    /** @param array<string, mixed> $payload */
    private function wonlexFrame(array $payload): string
    {
        return (new WonlexAdapter())->encodeOutgoing($payload);
    }
}
