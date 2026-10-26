<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tcp;

use Hub\Ingress\Tcp\DeviceEventDecoder;
use Hub\Ingress\Tcp\FourPTouch\FourPTouchAdapter;
use Hub\Ingress\Tcp\FourPTouch\FourPTouchTcpProtocol;
use Hub\Ingress\Tcp\Vivistar\VivistarAdapter;
use Hub\Ingress\Tcp\Vivistar\VivistarTcpProtocol;
use Hub\Ingress\Tcp\Zayata\PillDispenserAdapter;
use Hub\Ingress\Tcp\Zayata\PillDispenserTcpProtocol;
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
