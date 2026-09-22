<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O relógio calibra-se com a hora local do aparelho, não com UTC.
 *
 * A TAG `0xA101` leva uma string `2001-01-02T20:05:04` sem marca de fuso, e o M228 toma-a à
 * letra: põe o relógio exactamente ali e é isso que mostra no ecrã. O hub mandava
 * `gmdate(...)`, e no horário de verão de Lisboa o aparelho ficava **uma hora atrasado** —
 * o ecrã a dizer 09:29 às 10:29, e todos os nove alarmes a tocar uma hora depois do que a
 * dashboard mostra, porque as horas dos alarmes (`0x1021`--`0x1029`) também são locais.
 *
 * Isto apanhou-se com o aparelho na mesa: um alarme marcado para as 10:24 não tocou, e a
 * fotografia do ecrã mostrava 09:29 com o próximo alarme às 10:31. O comentário que já estava
 * no código -- «num ensaio um alarme das 12:55 ficou registado às 11:45» -- era o mesmo
 * defeito visto de outro ângulo e nunca diagnosticado.
 *
 * O fuso vem do que o hub tem configurado para o aparelho, na mesma unidade da TAG `0x1015`:
 * INT16S em HHMM, `+100` é uma hora à frente.
 */
final class PillDispenserClockCalibrationTest extends TestCase
{
    private const IMEI = '869243062262262';

    public function testTheClockIsSetToTheDeviceLocalTime(): void
    {
        $sent = $this->calibrateWith(['timeZone' => 100]);

        self::assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+60 minutes')
                ->format('Y-m-d\TH:i'),
            substr($sent, 0, 16),
            'o aparelho tem de receber a hora local, uma hora à frente de UTC',
        );
    }

    /** A oeste o sinal é negativo, e a conta tem de andar para trás. */
    public function testAWesternZoneMovesTheClockBack(): void
    {
        $sent = $this->calibrateWith(['timeZone' => -300]);

        self::assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('-180 minutes')
                ->format('Y-m-d\TH:i'),
            substr($sent, 0, 16),
        );
    }

    /**
     * Sem fuso conhecido fica UTC, que é o que o hub sabe de certeza.
     *
     * Adivinhar um fuso seria pôr o relógio do aparelho a uma hora inventada; UTC está errado
     * por um valor conhecido e igual para toda a gente.
     */
    public function testWithoutAKnownZoneItStaysUtc(): void
    {
        $sent = $this->calibrateWith([]);

        self::assertSame(
            gmdate('Y-m-d\TH:i'),
            substr($sent, 0, 16),
        );
    }

    /** O formato é o da especificação, sem marca de fuso e sem milissegundos. */
    public function testTheFormatIsTheOneTheSpecGives(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',
            $this->calibrateWith(['timeZone' => 100]),
        );
    }

    /** @param array<string, mixed> $context */
    private function calibrateWith(array $context): string
    {
        $bytes = DeviceCommandCatalog::buildDownlink(
            'zayata-m228',
            self::IMEI,
            'calibrateClock',
            [],
            $context,
        );

        $decoded = (new PillDispenserAdapter())->decodeIncoming($bytes);
        self::assertSame(0x08, $decoded['packetType'], 'a calibração é um pacote de controlo');

        return (string)($decoded['tlv'][0xA101]['value'] ?? '');
    }
}
