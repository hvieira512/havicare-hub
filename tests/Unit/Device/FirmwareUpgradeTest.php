<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use Hub\Device\Firmware\FirmwareUpgrade;
use PHPUnit\Framework\TestCase;

/**
 * A transferência do firmware é uma sequência travada pelo aparelho: cada pacote só sai
 * depois de ele confirmar o anterior, e é o offset no corpo que a sequencia.
 */
final class FirmwareUpgradeTest extends TestCase
{
    private const SIZE = 600;

    public function testTheStartPacketCarriesSizeChecksumAndTimeout(): void
    {
        $body = FirmwareUpgrade::startBody(210937, 16350232, 1800);

        self::assertSame(20, strlen($body), 'quatro, quatro, dois e dez reservados');
        self::assertSame(210937, unpack('V', substr($body, 0, 4))[1]);
        self::assertSame(16350232, unpack('V', substr($body, 4, 4))[1]);
        self::assertSame(1800, unpack('v', substr($body, 8, 2))[1]);
        self::assertSame(str_repeat("\x00", 10), substr($body, 10));
    }

    /** O corpo de um pacote de dados nunca passa dos 256: quatro de offset e 252 de ficheiro. */
    public function testADataPacketNeverExceedsTheAgreedSize(): void
    {
        $body = FirmwareUpgrade::dataBody(504, str_repeat('x', FirmwareUpgrade::CHUNK));

        self::assertSame(252, FirmwareUpgrade::CHUNK);
        self::assertSame(256, strlen($body));
        self::assertSame(504, unpack('V', substr($body, 0, 4))[1]);
    }

    /** Pedido o upgrade, o primeiro pacote a sair é o de arranque. */
    public function testTheTransferOpensWithTheStartPacket(): void
    {
        $step = FirmwareUpgrade::advance($this->state('requested', 0), $this->firmware(), 'heartbeat', 0);

        self::assertSame(0x0E, $step['packetType']);
        self::assertSame(20, strlen((string)$step['body']));
        self::assertSame('starting', $step['state']['status']);
    }

    /** Confirmado o arranque, sai o primeiro pedaço, do offset zero. */
    public function testTheFirstChunkFollowsTheStartAcknowledgement(): void
    {
        $step = FirmwareUpgrade::advance($this->state('starting', 0), $this->firmware(), 'upgrade_start_ack', 0);

        self::assertSame(0x0F, $step['packetType']);
        self::assertSame(0, unpack('V', substr((string)$step['body'], 0, 4))[1]);
        self::assertSame(FirmwareUpgrade::CHUNK, strlen((string)$step['body']) - 4);
        self::assertSame(252, $step['state']['offset']);
    }

    /**
     * A tabela do documento dá `0x8D` como resposta ao arranque e a secção 26 dá `0x8E`. O
     * fornecedor já disse que vai corrigir o documento; aceitar as duas evita ficar à espera.
     */
    public function testEitherAcknowledgementOpensTheTransfer(): void
    {
        foreach (['upgrade_start_ack', 'discover_event_ack'] as $reply) {
            $step = FirmwareUpgrade::advance($this->state('starting', 0), $this->firmware(), $reply, 0);

            self::assertSame(0x0F, $step['packetType'], $reply);
        }
    }

    /** Cada confirmação faz sair o pedaço seguinte, e o offset anda o tamanho do anterior. */
    public function testEachAcknowledgementAdvancesTheOffset(): void
    {
        $step = FirmwareUpgrade::advance($this->state('sending', 252), $this->firmware(), 'upgrade_data_ack', 0);

        self::assertSame(252, unpack('V', substr((string)$step['body'], 0, 4))[1]);
        self::assertSame(504, $step['state']['offset']);
    }

    /** O último pedaço leva só o que resta, e não enche o pacote. */
    public function testTheLastChunkCarriesOnlyTheRemainder(): void
    {
        $step = FirmwareUpgrade::advance($this->state('sending', 504), $this->firmware(), 'upgrade_data_ack', 0);

        self::assertSame(self::SIZE - 504, strlen((string)$step['body']) - 4);
        self::assertSame(self::SIZE, $step['state']['offset']);
    }

    /** Transferido tudo, o fim marca-se com o offset no tamanho do ficheiro e zero dados. */
    public function testTheTransferClosesWithAnEmptyPacketAtTheEnd(): void
    {
        $step = FirmwareUpgrade::advance($this->state('sending', self::SIZE), $this->firmware(), 'upgrade_data_ack', 0);

        self::assertSame(0x0F, $step['packetType']);
        self::assertSame(4, strlen((string)$step['body']), 'só o offset, sem dados');
        self::assertSame(self::SIZE, unpack('V', substr((string)$step['body'], 0, 4))[1]);
        self::assertSame('finishing', $step['state']['status']);
    }

    /** Confirmado o marcador, acabou, e não sai mais nada. */
    public function testTheTransferIsDoneAfterTheEndMarkerIsAcknowledged(): void
    {
        $step = FirmwareUpgrade::advance($this->state('finishing', self::SIZE), $this->firmware(), 'upgrade_data_ack', 0);

        self::assertSame('done', $step['state']['status']);
        self::assertNull($step['body']);
    }

    /**
     * O resultado vem no `Status` do cabeçalho, porque o corpo das respostas é vazio: `0x08` é
     * «Upgrade failed» e `0x07` é «Abnormal length». Qualquer um pára a transferência.
     */
    public function testARefusedPacketStopsTheTransfer(): void
    {
        foreach ([0x07, 0x08, 0x7F] as $frameStatus) {
            $step = FirmwareUpgrade::advance($this->state('sending', 252), $this->firmware(), 'upgrade_data_ack', $frameStatus);

            self::assertSame('failed', $step['state']['status'], sprintf('0x%02X', $frameStatus));
            self::assertNull($step['body']);
            self::assertSame($frameStatus, $step['state']['error']);
        }
    }

    /**
     * Uma ligação nova a meio da transferência manda recomeçar do princípio.
     *
     * O fornecedor foi claro: uma transferência interrompida não grava nada e a seguinte
     * recomeça do zero. Sem isto, o estado ficava parado num offset que já não vale.
     */
    public function testAFreshRegistrationRestartsTheTransfer(): void
    {
        foreach (['starting', 'sending', 'finishing'] as $status) {
            $step = FirmwareUpgrade::advance($this->state($status, 252), $this->firmware(), 'register', 0);

            self::assertSame('requested', $step['state']['status'], $status);
            self::assertSame(0, $step['state']['offset'], $status);
            self::assertNull($step['body'], 'o registo leva a confirmação dele, não um pacote de upgrade');
        }
    }

    /**
     * Cada pacote leva o número de série seguinte, como a especificação pede.
     *
     * Um número repetido a meio de 839 pacotes arrisca o aparelho tomar um por duplicado e
     * deitá-lo fora, e a transferência encalha sem dizer porquê.
     */
    public function testEachPacketCarriesTheNextSerial(): void
    {
        $first = FirmwareUpgrade::advance($this->state('requested', 0), $this->firmware(), 'heartbeat', 0);
        $second = FirmwareUpgrade::advance($first['state'], $this->firmware(), 'upgrade_start_ack', 0);
        $third = FirmwareUpgrade::advance($second['state'], $this->firmware(), 'upgrade_data_ack', 0);

        self::assertSame([1, 2, 3], [$first['state']['serial'], $second['state']['serial'], $third['state']['serial']]);
    }

    /** O campo tem dois bytes, e a contagem dá a volta em vez de transbordar. */
    public function testTheSerialWrapsInsteadOfOverflowing(): void
    {
        $state = ['serial' => 65535] + $this->state('requested', 0);

        self::assertSame(0, FirmwareUpgrade::advance($state, $this->firmware(), 'heartbeat', 0)['state']['serial']);
    }

    /** Uma trama que não diga respeito ao upgrade não mexe no estado nem faz sair nada. */
    public function testAnUnrelatedFrameChangesNothing(): void
    {
        $state = $this->state('sending', 252);
        $step = FirmwareUpgrade::advance($state, $this->firmware(), 'heartbeat', 0);

        self::assertSame($state, $step['state']);
        self::assertNull($step['body']);
    }

    private function firmware(): string
    {
        return str_repeat('f', self::SIZE);
    }

    /** @return array<string, mixed> */
    private function state(string $status, int $offset): array
    {
        return [
            'status' => $status,
            'offset' => $offset,
            'size' => self::SIZE,
            'checksum' => 123456,
            'timeout' => 1800,
        ];
    }
}
