<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Lefu;

use DateTimeImmutable;
use Hub\Ingress\Mqtt\Lefu\TorreCommandEncoder;
use PHPUnit\Framework\TestCase;

/** Os bytes esperados são os do SDK da Lefu e os que a sonda escreveu na CF632 a 2026-10-15. */
final class TorreCommandEncoderTest extends TestCase
{
    private const MTU = 61;

    /** No fio, `00` liga e `01` desliga. */
    public function testTheSwitchesAreInvertedOnTheWire(): void
    {
        self::assertSame([['char' => 'fff1', 'hex' => '06020100']], $this->writes('heartRateMeasurement', ['enabled' => true]));
        self::assertSame([['char' => 'fff1', 'hex' => '07020101']], $this->writes('impedanceMeasurement', ['enabled' => false]));
        self::assertSame([['char' => 'fff1', 'hex' => '05020101']], $this->writes('weightUnit', ['unit' => 'lb']));
    }

    /** As 10:41:23 de Lisboa: o unix, a hora do fuso à parte (como o SDK) e 24 horas. */
    public function testTheClockCarriesUnixTimeAndTheZoneHour(): void
    {
        self::assertSame(
            [['char' => 'fff1', 'hex' => '0406c39fd06a0101']],
            (new TorreCommandEncoder())->writes('calibrateClock', [], self::MTU, new DateTimeImmutable('2026-10-15T09:41:23Z'), 60),
        );
    }

    public function testSyncingReadsTheThreeSettings(): void
    {
        self::assertSame(
            [['char' => 'fff1', 'hex' => '05020200'], ['char' => 'fff1', 'hex' => '06020200'], ['char' => 'fff1', 'hex' => '07020200']],
            $this->writes('syncConfiguration', []),
        );
    }

    /** O registo do utilizador em linha da sonda: 128 bytes em três pacotes, à espera de cada passo. */
    public function testSelectingTheUserIsChunkedByMtuAndWaitsForEachStep(): void
    {
        $writes = $this->writes('selectScaleUser', ['userId' => 'havicare-hub-probe', 'memberId' => 'probe-member-1']);

        self::assertSame(['char' => 'fff2', 'hex' => '0503068000', 'await' => ['char' => 'fff2', 'prefix' => '050206', 'timeoutMs' => 3000]], $writes[0]);
        self::assertSame('0000' . bin2hex(str_pad('havicare-hub-probe', 59, "\0")), $writes[1]['hex']);
        self::assertSame(61, strlen((string)hex2bin($writes[1]['hex'])));
        self::assertSame(12, strlen((string)hex2bin($writes[3]['hex'])));
        self::assertStringStartsWith('0002', $writes[3]['hex']);
        self::assertSame(['char' => 'fff2', 'hex' => '050107', 'await' => ['char' => 'fff2', 'prefix' => '050207', 'timeoutMs' => 3000]], $writes[4]);
    }

    public function testRestartIsTheOnlyResetOnOffer(): void
    {
        self::assertSame([['char' => 'fff1', 'hex' => '0900']], $this->writes('restartDevice', []));

        $this->expectException(\InvalidArgumentException::class);
        $this->writes('factoryReset', []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function writes(string $operation, array $payload): array
    {
        return (new TorreCommandEncoder())->writes($operation, $payload, self::MTU, new DateTimeImmutable(), 60);
    }
}
