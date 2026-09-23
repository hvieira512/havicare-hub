<?php

declare(strict_types=1);

namespace Tests\Unit\Hub\Tcp;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Device\DeviceEventDecoder;
use Hub\Device\Tcp\Supplier\Zayata\PillDispenserTcpProtocol;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Uma configuração escrita tem de passar a «confirmada» quando o aparelho a confirma.
 *
 * O M228 respondia `SUCESSO` a tudo e a dashboard mostrava «falhou, tentativas esgotadas» em
 * todas as configurações -- e o hub reenviava de minuto a minuto, indefinidamente. Faltavam
 * três coisas ao mesmo tempo, e qualquer uma delas sozinha chegava para o ciclo nunca fechar:
 * as definições não diziam que resposta esperar, as tramas saíam todas com o número de série a
 * zero, e o protocolo não sabia ler o resultado que vinha no Flag de cada TAG.
 */
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
     * O nome do comando não serve de resposta esperada.
     *
     * Por omissão uma definição espera uma resposta com o nome do próprio comando --
     * `alarmVolume` --, e é o que a maioria dos protocolos faz. O M2 não: responde pelo tipo
     * de pacote, e um `0x06` volta sempre como `write_config_ack`, seja qual for a TAG que
     * levou. Com o valor por omissão o `markCommandReply` procurava um pendente à espera de
     * `alarmVolume`, não encontrava nenhum, e saía sem marcar coisa nenhuma.
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

        // `001` é «TAG inválida», que foi o que o M228 devolveu ao `0x8005`.
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
     * Numa leitura, uma TAG recusada é informação e não uma falha.
     *
     * O M228 de produção é a variante 4G e não tem WiFi: responde ao `0x07` com tudo o resto
     * preenchido e o `0x810A` em `001`. A leitura correu bem -- trouxe bateria, temperatura,
     * humidade e células --, e marcá-la como recusada punha «o aparelho recusou» num pedido
     * que devolveu toda a telemetria que havia para devolver.
     */
    public function testAReadThatAnswersIsAcceptedEvenComARefusedTag(): void
    {
        $protocol = $this->protocol();

        $leitura = (new PillDispenserAdapter())->encodeOutgoing([
            'imei' => self::IMEI,
            'packetType' => 0x87,
            'tlv' => [
                0x8103 => ['value' => "\x63", 'state' => 0],
                0x810A => ['value' => "\x00\x00", 'state' => 1],
            ],
        ]);

        self::assertTrue($protocol->replyAccepted($this->decodeFrame($leitura)));
    }

    public function testAFrameThatDoesNotCommentOnConfigurationSaysNothing(): void
    {
        $protocol = $this->protocol();

        // `null` não é «recusou», é «não disse»: um heartbeat não comenta configuração
        // nenhuma, e tratá-lo como recusa marcava como falhada uma escrita ainda a caminho.
        self::assertNull($protocol->replyAccepted(['type' => 'heartbeat', 'tlv' => []]));
        self::assertNull($protocol->replyAccepted(['type' => 'write_config_ack', 'tlv' => []]));
    }

    public function testTwoDownlinksInARowCarryDifferentSerials(): void
    {
        $primeiro = $this->decodeFrame(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, 'alarmVolume', ['volume' => 1])
        );
        $segundo = $this->decodeFrame(
            DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, 'alarmRingtone', ['ringtone' => 2])
        );

        // O número de série é o que o aparelho ecoa na resposta, e é por ele que se sabe a
        // qual dos pedidos pendentes ela pertence. Todos a zero e duas escritas ao mesmo
        // tempo ficavam indistinguíveis.
        self::assertNotSame('0', $primeiro['ident']);
        self::assertNotSame($primeiro['ident'], $segundo['ident']);
    }
}
