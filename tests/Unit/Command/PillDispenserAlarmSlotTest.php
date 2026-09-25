<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O alarme fica no slot que foi pedido, e não na posição que calhou na lista.
 *
 * Um construtor que ponha o enésimo plano no enésimo slot manda o alarme 5 para o 3, por cima
 * do que lá estivesse. O slot vai dentro de cada plano; quem não o mandar continua a ser
 * colocado por posição, que é o que os planos já guardados trazem.
 */
final class PillDispenserAlarmSlotTest extends TestCase
{
    private const IMEI = '869243062262262';

    public function testThePlanLandsOnTheSlotItAsksFor(): void
    {
        $tlv = $this->plan([
            ['slot' => 5, 'hour' => 10, 'minute' => 24, 'enabled' => true],
        ]);

        self::assertSame(10, $this->byte($tlv, 0x1025), 'hora do alarme 5');
        self::assertSame(24, $this->byte($tlv, 0x1035), 'minuto do alarme 5');
        self::assertSame(1, $this->byte($tlv, 0x1045), 'interruptor do alarme 5');

        self::assertSame(0, $this->byte($tlv, 0x1043), 'o alarme 3 não foi tocado');
        self::assertSame(24, $this->byte($tlv, 0x1023));
    }

    /**
     * Os nove vão sempre, e um slot que o plano não use vai vazio.
     *
     * O vazio do aparelho é `24:60`, não `00:00`: a hora a zero é a meia-noite, e oito slots
     * por preencher gastavam uma dose todos os dias à meia-noite.
     */
    public function testTheUnusedSlotsAreEmptyAndNotMidnight(): void
    {
        $tlv = $this->plan([['slot' => 9, 'hour' => 8, 'minute' => 0]]);

        foreach (range(0, 7) as $offset) {
            self::assertSame(24, $this->byte($tlv, 0x1021 + $offset), 'hora do alarme ' . ($offset + 1));
            self::assertSame(60, $this->byte($tlv, 0x1031 + $offset), 'minuto do alarme ' . ($offset + 1));
            self::assertSame(0, $this->byte($tlv, 0x1041 + $offset), 'interruptor do alarme ' . ($offset + 1));
        }
        self::assertSame(8, $this->byte($tlv, 0x1029), 'hora do alarme 9');
        self::assertSame(1, $this->byte($tlv, 0x1049), 'alarme 9');
    }

    /**
     * Um plano guardado com o interruptor desligado passa a ir vazio.
     *
     * O interruptor nunca calou nada — a firmware ignora-o —, e quem o desligou queria o
     * alarme calado. O `24:60` é o que finalmente lho dá.
     */
    public function testASlotSwitchedOffInAStoredPlanGoesOutEmpty(): void
    {
        $tlv = $this->plan([['slot' => 1, 'hour' => 16, 'minute' => 30, 'enabled' => false]]);

        self::assertSame(24, $this->byte($tlv, 0x1021));
        self::assertSame(60, $this->byte($tlv, 0x1031));
    }

    /** Um plano guardado antes desta mudança não traz slot, e continua a valer por posição. */
    public function testAPlanWithoutSlotsKeepsItsPositions(): void
    {
        $tlv = $this->plan([
            ['hour' => 11, 'minute' => 0, 'enabled' => true],
            ['hour' => 20, 'minute' => 30, 'enabled' => true],
        ]);

        self::assertSame(11, $this->byte($tlv, 0x1021));
        self::assertSame(20, $this->byte($tlv, 0x1022));
        self::assertSame(30, $this->byte($tlv, 0x1032));
    }

    /** Dois planos no mesmo slot é um pedido incoerente, e cala-se mal se for aceite. */
    public function testTwoPlansOnTheSameSlotAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->plan([
            ['slot' => 4, 'hour' => 9, 'minute' => 0, 'enabled' => true],
            ['slot' => 4, 'hour' => 21, 'minute' => 0, 'enabled' => true],
        ]);
    }

    /** O aparelho tem nove, e um décimo slot não existe em lado nenhum. */
    public function testASlotOutsideTheNineIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->plan([['slot' => 10, 'hour' => 9, 'minute' => 0, 'enabled' => true]]);
    }

    /**
     * @param list<array<string, mixed>> $plans
     * @return array<int, array{value?: string}>
     */
    private function plan(array $plans): array
    {
        $bytes = DeviceCommandCatalog::buildDownlink(
            'zayata-m228',
            self::IMEI,
            'medicationPlan',
            ['plans' => $plans],
        );

        return (new PillDispenserAdapter())->decodeIncoming($bytes)['tlv'] ?? [];
    }

    /** @param array<int, array{value?: string}> $tlv */
    private function byte(array $tlv, int $tag): int
    {
        return ord((string)($tlv[$tag]['value'] ?? "\xFF"));
    }
}
