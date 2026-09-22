<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O estado de toma de cada um dos nove alarmes, que o aparelho diz em claro.
 *
 * O evento de toma (`0x03`) é a leitura rica -- traz a hora prevista, a hora real e a célula
 * -- e chega cifrado, porque o M228 cifra tudo o que envia por iniciativa própria e a chave
 * sai da codificação dele. As TAGs `0x8131`--`0x8139` respondem à mesma pergunta por outro
 * caminho: são estado, pedem-se num `0x07`, e a resposta a um pedido nosso vem sempre em
 * claro. Não dão a hora nem a célula, mas dizem se cada alarme foi tomado, falhado ou está à
 * espera, que é o que o produto precisa de mostrar.
 */
final class PillDispenserAlarmStatusTest extends TestCase
{
    public function testEachAlarmStateBecomesTelemetry(): void
    {
        $events = $this->statusEvents([
            0x8131 => "\x07",   // alarme 1: tomado
            0x8132 => "\x02",   // alarme 2: à espera
            0x8133 => "\x06",   // alarme 3: falhado
            0x8134 => "\x00",   // alarme 4: sem nada
            0x8135 => "\x04",   // alarme 5: esgotou o tempo
            0x8136 => "\x01",   // alarme 6: a preparar
        ]);

        // `complete` diz que a trama perguntou pelos nove: só aí é que as contagens são
        // totais. Uma notificação traz o alarme que mudou e não conta nada.
        self::assertSame([
            'takenCount' => 1,
            'missedCount' => 1,
            'complete' => true,
            'alarms' => [
                ['alarm' => 1, 'state' => 'taken'],
                ['alarm' => 2, 'state' => 'waiting'],
                ['alarm' => 3, 'state' => 'missed'],
                ['alarm' => 4, 'state' => 'idle'],
                ['alarm' => 5, 'state' => 'timed_out'],
                ['alarm' => 6, 'state' => 'preparing'],
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
     * Uma TAG recusada não conta como alarme inactivo.
     *
     * O estado nos bits 5--7 do Flag distingue uma leitura de um eco: o aparelho devolve os
     * bytes que lhe mandámos -- zeros -- com um estado diferente de `000`. Publicar isso como
     * «sem nada» era inventar que o alarme existe e está parado.
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

    /**
     * O `0x07` tem de pedir as nove, senão o aparelho nunca as manda.
     *
     * O heartbeat traz o estado que lhe apetece; as nove só chegam porque são pedidas.
     */
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
