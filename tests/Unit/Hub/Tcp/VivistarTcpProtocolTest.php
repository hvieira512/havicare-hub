<?php

declare(strict_types=1);

namespace Tests\Unit\Hub\Tcp;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Ingress\Tcp\Supplier\Vivistar\VivistarTcpProtocol;
use Hub\Protocol\Adapter\VivistarAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeConnection;

final class VivistarTcpProtocolTest extends TestCase
{
    public function testHandleIncomingBuildsTelemetryAndUploadAck(): void
    {
        $protocol = new VivistarTcpProtocol(new VivistarAdapter(), new DeviceEventDecoder());
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
        $protocol = new VivistarTcpProtocol(new VivistarAdapter(), new DeviceEventDecoder());

        self::assertSame([
            'nativeType' => 'BPXY',
            'protocol' => 'vivistar-iw',
            'ident' => '080835',
        ], $protocol->commandMetadata('IWBPXY,861265061009822,080835,1#'));
    }

    private function session(string $imei): DeviceSession
    {
        return new DeviceSession(new FakeConnection(), true, $imei, 'vivistar-iw');
    }
}
