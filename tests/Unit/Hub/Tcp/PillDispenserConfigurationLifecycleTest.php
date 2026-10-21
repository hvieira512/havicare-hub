<?php

declare(strict_types=1);

namespace Tests\Unit\Hub\Tcp;

use Hub\Command\DeviceCommandCatalog;
use Hub\Device\DeviceEventDecoder;
use Hub\Ingress\Tcp\Supplier\Zayata\PillDispenserTcpProtocol;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/** Uma configuração escrita passa a «confirmada» quando o aparelho a confirma. */
final class PillDispenserConfigurationLifecycleTest extends TestCase
{
    private const IMEI = '869243062262262';

    private function protocol(): PillDispenserTcpProtocol
    {
        return new PillDispenserTcpProtocol(new PillDispenserAdapter(), new DeviceEventDecoder());
    }

    /** @return array<string, mixed> */
    private function decodeFrame(string $frame): array
    {
        $decoded = (new PillDispenserAdapter())->decodeIncoming($frame);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testTheDeviceConfirmingEveryTagIsAnAcceptedReply(): void
    {
        $protocol = $this->protocol();

        $aceite = (new PillDispenserAdapter())->encodeOutgoing([
            'imei' => self::IMEI,
            'packetType' => 0x86,
            'tlv' => [
                0x1013 => ['value' => "\x00", 'state' => 0],
                0x1012 => ['value' => "\x00", 'state' => 0],
            ],
        ]);

        self::assertTrue($protocol->replyAccepted($this->decodeFrame($aceite)));
    }

    public function testOneRefusedTagIsEnoughToRefuseTheWhole(): void
    {
        $protocol = $this->protocol();

        // `001` é «TAG inválida».
        $recusado = (new PillDispenserAdapter())->encodeOutgoing([
            'imei' => self::IMEI,
            'packetType' => 0x86,
            'tlv' => [
                0x1013 => ['value' => "\x00", 'state' => 0],
                0x8005 => ['value' => "\x00", 'state' => 1],
            ],
        ]);

        self::assertFalse($protocol->replyAccepted($this->decodeFrame($recusado)));
    }

    /** O M228 4G não tem WiFi: recusar o `0x810A` numa leitura com o resto da telemetria não é recusar o pedido. */
    public function testAReadThatAnswersIsAcceptedEvenComARefusedTag(): void
    {
        $protocol = $this->protocol();

        $reading = (new PillDispenserAdapter())->encodeOutgoing([
            'imei' => self::IMEI,
            'packetType' => 0x87,
            'tlv' => [
                0x8103 => ['value' => "\x63", 'state' => 0],
                0x810A => ['value' => "\x00\x00", 'state' => 1],
            ],
        ]);

        self::assertTrue($protocol->replyAccepted($this->decodeFrame($reading)));
    }

    public function testAFrameThatDoesNotCommentOnConfigurationSaysNothing(): void
    {
        $protocol = $this->protocol();

        // `null` não é «recusou», é «não disse»: um heartbeat não comenta configuração nenhuma.
        self::assertNull($protocol->replyAccepted(['type' => 'heartbeat', 'tlv' => []]));
        self::assertNull($protocol->replyAccepted(['type' => 'write_config_ack', 'tlv' => []]));
    }

    public function testTwoDownlinksInARowCarryDifferentSerials(): void
    {
        $first = $this->decodeFrame(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, 'alarmVolume', ['volume' => 1])
        );
        $second = $this->decodeFrame(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, 'alarmRingtone', ['ringtone' => 2])
        );

        // O aparelho ecoa o número de série na resposta, e é por ele que se sabe a que pedido
        // pendente ela pertence.
        self::assertNotSame('0', $first['ident']);
        self::assertNotSame($first['ident'], $second['ident']);
    }
}
