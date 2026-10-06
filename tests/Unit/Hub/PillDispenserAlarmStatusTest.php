<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O estado de cada um dos nove alarmes, pelas TAGs `0x8131`--`0x8139` pedidas num `0x07`: vêm
 * em claro, mas sem a hora nem a célula do evento cifrado `0x03`.
 */
final class PillDispenserAlarmStatusTest extends TestCase
{
    public function testEachAlarmStateBecomesTelemetry(): void
    {
        $events = $this->statusEvents([
            0x8131 => "\x07",   // alarme 1: tomado
            0x8132 => "\x02",   // alarme 2: à espera de sair
            0x8133 => "\x06",   // alarme 3: falhado
            0x8134 => "\x00",   // alarme 4: sem nada
            0x8135 => "\x04",   // alarme 5: esgotou o tempo de sair
            0x8136 => "\x01",   // alarme 6: a preparar
            0x8137 => "\x03",   // alarme 7: saiu, à espera de ser levantada
            0x8138 => "\x05",   // alarme 8: esgotou o tempo de levantamento
        ]);

        // As contagens são totais porque a trama perguntou pelos nove. Uma notificação traz o
        // alarme que mudou e sai por outra capacidade, que não conta nada.
        self::assertSame([
            'takenCount' => 1,
            'missedCount' => 1,
            'alarms' => [
                ['alarm' => 1, 'state' => 'taken'],
                ['alarm' => 2, 'state' => 'waiting'],
                ['alarm' => 3, 'state' => 'missed'],
                ['alarm' => 4, 'state' => 'idle'],
                ['alarm' => 5, 'state' => 'timed_out'],
                ['alarm' => 6, 'state' => 'preparing'],
                ['alarm' => 7, 'state' => 'awaiting_retrieval'],
                ['alarm' => 8, 'state' => 'retrieval_timed_out'],
            ],
        ], $events['medication_alarm_status'] ?? null);
    }

    /** Sem nenhuma das nove TAGs não há capacidade nenhuma a publicar. */
    public function testNothingIsPublishedWhenNoAlarmReports(): void
    {
        $events = $this->statusEvents([0x8101 => "\x00"]);

        self::assertArrayNotHasKey('medication_alarm_status', $events);
    }

    /**
     * O aparelho devolve uma TAG recusada com os zeros que lhe mandámos, e é o estado nos bits
     * 5--7 do Flag que a distingue de um alarme inactivo.
     */
    public function testARefusedTagIsNotReportedAsIdle(): void
    {
        $events = $this->statusEvents([
            0x8131 => "\x07",
            0x8132 => ['value' => "\x00", 'state' => 1],
        ]);

        self::assertSame(
            [['alarm' => 1, 'state' => 'taken']],
            $events['medication_alarm_status']['alarms'] ?? null,
        );
    }

    /** O heartbeat traz o estado que lhe apetece, e as nove só chegam se o `0x07` as pedir. */
    public function testTheStatusQueryAsksForAllNine(): void
    {
        foreach (range(0x8131, 0x8139) as $tag) {
            self::assertContains($tag, PillDispenserAdapter::STATUS_TAGS, sprintf('0x%04X', $tag));
        }
    }

    /**
     * @param array<int, string|array{value: string, state?: int}> $tlv
     * @return array<string, array<string, mixed>>
     */
    private function statusEvents(array $tlv): array
    {
        $entries = [];
        foreach ($tlv as $tag => $item) {
            $entries[$tag] = is_array($item) ? $item : ['value' => $item];
        }

        // O estado da leitura viaja nos bits 5--7 do Flag de cada TLV, e por isso vai na
        // própria trama: não há nada a remendar depois de descodificar.
        $decoded = (new PillDispenserAdapter())->decodeIncoming(
            (new PillDispenserAdapter())->encodeOutgoing([
                'packetType' => 0x87,
                'serial' => 1,
                'deviceNumber' => PillDispenserAdapter::deviceNumberFor('869243062262262'),
                'tlv' => $entries,
            ])
        );

        $byFeature = [];
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            $byFeature[$event['feature']] = $event['value'];
        }

        return $byFeature;
    }

    private function session(): DeviceSession
    {
        return new DeviceSession(
            new PillFakeConnection(),
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
