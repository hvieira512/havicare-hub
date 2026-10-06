<?php

declare(strict_types=1);

namespace Tests\Unit\Hub\Tcp;

use Hub\Device\DeviceEventDecoder;
use Hub\Ingress\Tcp\Supplier\FourPTouch\FourPTouchTcpProtocol;
use Hub\Ingress\Tcp\Supplier\Vivistar\VivistarTcpProtocol;
use Hub\Ingress\Tcp\Supplier\Zayata\PillDispenserTcpProtocol;
use Hub\Protocol\Adapter\FourPTouchAdapter;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use Hub\Protocol\Adapter\VivistarAdapter;
use PHPUnit\Framework\TestCase;

/** Saber se o aparelho aceitou uma configuração é conhecimento do protocolo, e não do servidor TCP. */
final class ConfigurationReplyAcceptanceTest extends TestCase
{
    private function fourPTouch(): FourPTouchTcpProtocol
    {
        return new FourPTouchTcpProtocol(new FourPTouchAdapter(), new DeviceEventDecoder());
    }

    public function testTheFourPTouchReadsTheAcknowledgementOfAPillReminder(): void
    {
        $protocol = $this->fourPTouch();

        self::assertTrue($protocol->replyAccepted(['type' => 'TAKEPILLS', 'data' => ['configAck' => '1']]));
        self::assertFalse($protocol->replyAccepted(['type' => 'TAKEPILLS', 'data' => ['configAck' => '0']]));
    }

    public function testAnUnknownAcknowledgementIsNotAnAnswer(): void
    {
        $protocol = $this->fourPTouch();

        // `null` não é «recusou», é «não disse»: tratá-lo como recusa marcaria como falhada uma
        // configuração que o aparelho nem comentou.
        self::assertNull($protocol->replyAccepted(['type' => 'TAKEPILLS', 'data' => []]));
        self::assertNull($protocol->replyAccepted(['type' => 'TAKEPILLS', 'data' => ['configAck' => 'x']]));
        self::assertNull($protocol->replyAccepted(['type' => 'LK', 'data' => ['configAck' => '1']]));
    }

    public function testOtherProtocolsDoNotClaimAnAnswerTheyNeverGave(): void
    {
        $vivistar = new VivistarTcpProtocol(new VivistarAdapter(), new DeviceEventDecoder());
        $dispenser = new PillDispenserTcpProtocol(new PillDispenserAdapter(), new DeviceEventDecoder());

        self::assertNull($vivistar->replyAccepted(['type' => 'AP01', 'data' => ['configAck' => '1']]));
        self::assertNull($dispenser->replyAccepted(['type' => 'write_config_ack', 'tlv' => []]));
    }
}
