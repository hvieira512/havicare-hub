<?php

declare(strict_types=1);

namespace Tests\Unit\Hub\Watch;

use Hub\DeviceEventDecoder;
use Hub\DeviceSession;
use Hub\Protocol\Adapter\VivistarAdapter;
use Hub\Watch\Supplier\Vivistar\VivistarWatchProtocol;
use PHPUnit\Framework\TestCase;

final class VivistarWatchProtocolTest extends TestCase
{
    public function testHandleIncomingBuildsTelemetryAndUploadAck(): void
    {
        $protocol = new VivistarWatchProtocol(new VivistarAdapter(), new DeviceEventDecoder());
        $session = $this->session('865028000000308');

        $message = $protocol->handleIncoming($session, 'IWAP49,72#');

        self::assertNotNull($message);
        self::assertCount(1, $message->telemetry);
        self::assertCount(1, $message->responses);
        self::assertSame('heart_rate', $message->telemetry[0]['type']);
        self::assertSame('IWBP49#', $message->responses[0]->bytes);
    }

    public function testCommandMetadataParsesVivistarDownlinkBytes(): void
    {
        $protocol = new VivistarWatchProtocol(new VivistarAdapter(), new DeviceEventDecoder());

        self::assertSame([
            'nativeType' => 'BPXY',
            'protocol' => 'vivistar-iw',
            'ident' => '080835',
        ], $protocol->commandMetadata('IWBPXY,861265061009822,080835,1#'));
    }

    private function session(string $imei): DeviceSession
    {
        return new DeviceSession(new VivistarFakeConnection(), 'tcp', true, $imei, 'vivistar-iw');
    }
}

final class VivistarFakeConnection implements \Hub\ConnectionInterface
{
    public int $resourceId = 1;

    public function remoteAddress(): ?string
    {
        return null;
    }

    public function send($data): static
    {
        return $this;
    }

    public function close(): static
    {
        return $this;
    }
}
