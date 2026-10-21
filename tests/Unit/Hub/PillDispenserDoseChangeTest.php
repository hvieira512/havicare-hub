<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/** As TAGs `0x8131`--`0x8139` são o retrato dos nove no `0x07` e o alarme que mudou numa notificação: duas capacidades, dois canais. */
final class PillDispenserDoseChangeTest extends TestCase
{
    /** Sem contagens: um total tirado de um alarme não é total nenhum. */
    public function testANotificationIsADoseChange(): void
    {
        $byFeature = $this->decode(0x04, [0x8133 => "\x06"]);

        self::assertArrayNotHasKey('medication_alarm_status', $byFeature);
        self::assertSame(
            ['alarm' => 3, 'state' => 'missed'],
            $byFeature['medication_alarm_change'] ?? null,
        );
    }

    /** O heartbeat também não pergunta pelos nove: o que ele traz é o que mudou. */
    public function testAHeartbeatIsAlsoAChange(): void
    {
        $byFeature = $this->decode(0x02, [0x8133 => "\x06"]);

        self::assertArrayNotHasKey('medication_alarm_status', $byFeature);
        self::assertSame(['alarm' => 3, 'state' => 'missed'], $byFeature['medication_alarm_change'] ?? null);
    }

    /** Dois acontecimentos numa mensagem obrigariam quem consome a desempacotar uma lista. */
    public function testTwoChangesInOneNotificationAreTwoEvents(): void
    {
        $adapter = new PillDispenserAdapter();
        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x04,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [0x8132 => ['value' => "\x07"], 0x8135 => ['value' => "\x06"]],
        ]));

        $changes = [];
        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            if ($event['feature'] === 'medication_alarm_change') {
                $changes[] = $event['value'];
            }
        }

        self::assertSame([
            ['alarm' => 2, 'state' => 'taken'],
            ['alarm' => 5, 'state' => 'missed'],
        ], $changes);
    }

    /**
     * @param array<int, string> $tlv
     * @return array<string, array<string, mixed>>
     */
    private function decode(int $packetType, array $tlv): array
    {
        $adapter = new PillDispenserAdapter();
        $entries = [];
        foreach ($tlv as $tag => $value) {
            $entries[$tag] = ['value' => $value];
        }

        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => $packetType,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => $entries,
        ]));

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
            true,
            'AABBCCDDEEFF',
            'zayata-m228',
            'Zayata',
            'M228',
            'Zayata M228',
            'pill_dispenser',
        );
    }
}
