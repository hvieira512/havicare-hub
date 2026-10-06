<?php

declare(strict_types=1);

namespace Tests\Unit\Hub\Tcp;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
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

    /**
     * O M2 responde pelo tipo de pacote e não pelo nome do comando: um `0x06` volta sempre como
     * `write_config_ack`, seja qual for a TAG que levou.
     */
    public function testEveryConfigurationWaitsForTheAcknowledgementOfItsPacketType(): void
    {
        $expected = [
            'alarm_volume' => 'write_config_ack',
            'alarm_ringtone' => 'write_config_ack',
            'do_not_disturb' => 'write_config_ack',
            'medication_reminders' => 'write_config_ack',
            'medication_period' => 'write_config_ack',
            'early_dispense' => 'write_config_ack',
            'child_lock' => 'write_config_ack',
            'device_language' => 'write_config_ack',
            'time_zone' => 'write_config_ack',
            'sync_configuration' => 'read_config_ack',
            'dispense_now' => 'control_ack',
            'calibrate_clock' => 'control_ack',
            'mute_alarm' => 'control_ack',
            'reset_tray' => 'control_ack',
            'restart_device' => 'control_ack',
        ];

        $actual = [];
        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            $actual[(string)$entry['key']] = $entry['expectedReplyTypes'] ?? [];
        }

        foreach ($expected as $key => $reply) {
            self::assertSame([$reply], $actual[$key] ?? null, $key);
        }
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

    /**
     * O M228 4G não tem WiFi e recusa o `0x810A` numa leitura que trouxe o resto da telemetria:
     * isso não é o aparelho a recusar o pedido.
     */
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
