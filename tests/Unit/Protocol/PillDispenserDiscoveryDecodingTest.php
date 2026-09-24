<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\Tcp\Supplier\Zayata\PillDispenserTcpProtocol;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O hub já não pergunta que parâmetros o firmware serve, mas tem de continuar a saber não ler
 * a resposta.
 *
 * O corpo de um `0x8A`–`0x8C` é uma lista de TAGs coladas e não TFLV. Lido como TFLV, dá
 * telemetria fabricada com identidade correcta e CRC válido — a pior falha calada que este
 * protocolo permite, porque nada a jusante tem como a distinguir de uma leitura verdadeira.
 */
final class PillDispenserDiscoveryDecodingTest extends TestCase
{
    /** Uma lista de TAGs bem formada não vira telemetria. */
    public function testATagListIsNeverReadAsTlv(): void
    {
        $decoded = $this->decode(0x8C, pack('v*', 0xA001, 0xA002, 0xA101));

        self::assertSame([], $decoded['tlv']);
        self::assertSame([], (new DeviceEventDecoder())->decode($this->session(), $decoded));
    }

    /** E um corpo que não se entende também não. */
    public function testAnUnreadableAnswerPublishesNothing(): void
    {
        // Comprimento ímpar: não fecha como lista de TAGs nem como TFLV.
        $decoded = $this->decode(0x8B, "\x01\x81\x02");

        self::assertSame([], $decoded['tlv'], 'nada é lido de um corpo que não se entende');
        self::assertSame([], (new DeviceEventDecoder())->decode($this->session(), $decoded));
    }

    /**
     * O `0x8D` não é uma resposta que se saiba ler.
     *
     * Esteve dentro da gama tratada como descoberta, o que o fazia fechar como aceite
     * qualquer operação pendente, enquanto o descodificador o via como `unknown`.
     */
    public function testAnUnnamedReplyDoesNotCloseAnything(): void
    {
        $decoded = $this->decode(0x8D, pack('v*', 0x1001));

        self::assertSame('unknown', $decoded['type']);
        self::assertNull($this->protocol()->replyAccepted($decoded));
    }

    /** @return array<string, mixed> */
    private function decode(int $packetType, string $appData): array
    {
        $adapter = new PillDispenserAdapter();
        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => $packetType,
            'serial' => 1,
            'deviceNumber' => PillDispenserAdapter::deviceNumberFor('869243062262262'),
            'appDataRaw' => $appData,
        ]));
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function protocol(): PillDispenserTcpProtocol
    {
        return new PillDispenserTcpProtocol(new PillDispenserAdapter(), new DeviceEventDecoder());
    }

    private function session(): \Hub\Device\DeviceSession
    {
        return new \Hub\Device\DeviceSession(
            new \Tests\Unit\Hub\PillFakeConnection(),
            'tcp',
            true,
            '869243062262262',
            'zayata-m228',
            'Zayata',
            'M228',
            'Zayata M228',
            'pill_dispenser',
        );
    }
}
