<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\Tcp\Supplier\Zayata\PillDispenserTcpProtocol;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * A descoberta de parâmetros, decodificada a partir de tramas a sério.
 *
 * O teste que já existia construía à mão o `supportedTags` que esperava encontrar, e por isso
 * passava sobre uma forma que o adaptador nunca produz. Aqui as tramas são montadas e
 * descodificadas pelo adaptador, que é o único caminho por onde uma resposta real entra.
 */
final class PillDispenserDiscoveryDecodingTest extends TestCase
{
    /**
     * Um firmware que não serve nenhum parâmetro daquela família responde com corpo vazio, e o
     * pedido tem de fechar na mesma.
     *
     * Devolvia `null`, que é «não disse»: o pedido ficava para sempre «a aguardar resposta do
     * dispositivo» e era repetido de minuto a minuto, com o aparelho a responder de cada vez.
     */
    public function testAnEmptyAnswerClosesTheRequest(): void
    {
        $decoded = $this->decode(0x8B, '');

        self::assertSame([], $decoded['supportedTags'], 'sem TAGs, mas respondeu');
        self::assertTrue($this->protocol()->replyAccepted($decoded));
    }

    public function testATagListIsReadInHostOrder(): void
    {
        $decoded = $this->decode(0x8C, pack('v*', 0xA001, 0xA002, 0xA101));

        self::assertSame([0xA001, 0xA002, 0xA101], $decoded['supportedTags']);
        self::assertTrue($this->protocol()->replyAccepted($decoded));
    }

    /**
     * Um corpo que não é uma lista de TAGs não pode ser lido como TFLV.
     *
     * Caía no `parseTlv` sobre os mesmos bytes, e o que saísse dali virava telemetria
     * inventada com identidade correcta e CRC válido -- a falha calada que este protocolo
     * torna fácil. É a mesma razão por que a decifra tem a sua própria guarda.
     */
    public function testAnUnreadableAnswerPublishesNothing(): void
    {
        // Comprimento ímpar: não fecha como lista de TAGs nem como TFLV.
        $decoded = $this->decode(0x8B, "\x01\x81\x02");

        self::assertNull($decoded['supportedTags']);
        self::assertSame([], $decoded['tlv'], 'nada é lido de um corpo que não se entende');
        self::assertSame([], (new DeviceEventDecoder())->decode($this->session(), $decoded));
    }

    /** Uma família de TAGs que não conhecemos também não vira TFLV. */
    public function testAnUnknownTagFamilyPublishesNothing(): void
    {
        $decoded = $this->decode(0x8B, pack('v*', 0x8201, 0x8202));

        self::assertNull($decoded['supportedTags']);
        self::assertSame([], $decoded['tlv']);
    }

    /**
     * O `0x8D` não é uma resposta que se saiba ler.
     *
     * Estava dentro da gama tratada como descoberta, o que o fazia fechar como aceite
     * qualquer operação pendente -- enquanto o descodificador o via como `unknown` e não
     * publicava nada.
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
