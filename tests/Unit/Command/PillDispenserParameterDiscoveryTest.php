<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Hub\PillFakeConnection;

/**
 * Perguntar ao aparelho que parâmetros ele suporta, em vez de adivinhar por recusa.
 *
 * Sem isto, a única maneira de saber se um firmware serve uma TAG era mandá-la e ler a
 * recusa. Foi assim que se descobriu que este M228 não tem WiFi — o `0x810A` voltava sempre
 * com «TAG inválida» —, e é um método que não escala para uma frota com firmwares diferentes.
 *
 * Os pacotes `0x0A`, `0x0B` e `0x0C` perguntam pelos parâmetros de configuração, de estado e
 * de controlo, e a resposta é uma lista simples de TAGs de dois bytes, sem TFLV pelo meio. O
 * fornecedor confirmou que este firmware os serve, e as respostas deste teste são as que o
 * aparelho de ensaio deu a 22 de setembro de 2026.
 */
final class PillDispenserParameterDiscoveryTest extends TestCase
{
    private const IMEI = '869243062262262';

    /** A resposta real do aparelho ao `0x0C`: doze TAGs de controlo. */
    private const CONTROL_REPLY = 'AA29000001030A0001000262222662302469488C01A002A003A004A011A021'
        . 'A022A023A001A102A103A123A174EC';

    public function testTheRequestIsAnEmptyPacketOfTheRightType(): void
    {
        foreach ([0x0A => 'Configuration', 0x0B => 'Status', 0x0C => 'Control'] as $type => $what) {
            $frame = DeviceCommandCatalog::buildDownlink(
                'zayata-m228',
                self::IMEI,
                'discoverParameters' . $what,
                [],
            );
            $decoded = (new PillDispenserAdapter())->decodeIncoming($frame);

            self::assertIsArray($decoded, $what);
            self::assertSame($type, $decoded['packetType'], $what);
            self::assertSame([], $decoded['tlv'], "o pedido {$what} não leva corpo");
        }
    }

    /**
     * A resposta não é TFLV: é uma lista de TAGs coladas, em ordem de anfitrião.
     *
     * Lê-las como TFLV dava TAGs inventadas — foi o que aconteceu à primeira, com o `0xA002`
     * a aparecer como `0x02A0`.
     */
    public function testTheReplyIsAFlatListOfTags(): void
    {
        $decoded = (new PillDispenserAdapter())->decodeIncoming((string)hex2bin(self::CONTROL_REPLY));

        self::assertSame('discover_control_ack', $decoded['type']);
        self::assertSame(
            [0xA001, 0xA002, 0xA003, 0xA004, 0xA011, 0xA021, 0xA022, 0xA023, 0xA101, 0xA102, 0xA103, 0xA123],
            $decoded['supportedTags'],
        );
    }

    /** E sai como telemetria, para quem integrar o hub saber o que o firmware serve. */
    public function testTheAnsweredTagsBecomeTelemetry(): void
    {
        $decoded = (new PillDispenserAdapter())->decodeIncoming((string)hex2bin(self::CONTROL_REPLY));
        $events = (new DeviceEventDecoder())->decode($this->session(), $decoded);

        // Pela chave com que a capacidade é declarada no catálogo: um nome publicado que
        // ninguém declara é um nome que ninguém recebe.
        self::assertCount(1, $events);
        self::assertSame('supported_control', $events[0]['feature']);
        self::assertSame(12, $events[0]['value']['count']);
        self::assertContains('0xA002', $events[0]['value']['tags']);
    }

    private function session(): DeviceSession
    {
        return new DeviceSession(
            new PillFakeConnection(),
            'tcp',
            true,
            self::IMEI,
            'zayata-m228',
            'Zayata',
            'M228',
            'Zayata M228',
            'pill_dispenser',
        );
    }
}
