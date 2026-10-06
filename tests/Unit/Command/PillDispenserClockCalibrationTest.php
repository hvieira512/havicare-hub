<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * A TAG `0xA101` não leva fuso e as horas dos alarmes são locais. O fuso é o configurado no hub, em
 * INT16S HHMM como a TAG `0x1015`: `+100` é uma hora à frente.
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
     * Sem fuso conhecido fica UTC: errado por um valor conhecido, e não por uma hora
     * inventada.
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
