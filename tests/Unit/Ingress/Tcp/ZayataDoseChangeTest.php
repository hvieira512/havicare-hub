<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tcp;

use Hub\Ingress\Tcp\DeviceEventDecoder;
use Hub\Ingress\Tcp\Zayata\ZayataAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ZayataFixtures;

/** As TAGs `0x8131`--`0x8139` são o retrato dos nove no `0x07` e o alarme que mudou numa notificação: duas capacidades, dois canais. */
final class ZayataDoseChangeTest extends TestCase
{
    /** Sem contagens: um total tirado de um alarme não é total nenhum. */
    public function testANotificationIsADoseChange(): void
    {
        $byFeature = ZayataFixtures::features(0x04, [0x8133 => "\x06"]);

        self::assertArrayNotHasKey('medication_alarm_status', $byFeature);
        self::assertSame(
            ['slot' => 3, 'state' => 'missed'],
            $byFeature['medication_alarm_change'] ?? null,
        );
    }

    /** O heartbeat também não pergunta pelos nove: o que ele traz é o que mudou. */
    public function testAHeartbeatIsAlsoAChange(): void
    {
        $byFeature = ZayataFixtures::features(0x02, [0x8133 => "\x06"]);

        self::assertArrayNotHasKey('medication_alarm_status', $byFeature);
        self::assertSame(['slot' => 3, 'state' => 'missed'], $byFeature['medication_alarm_change'] ?? null);
    }

    /** Dois acontecimentos numa mensagem obrigariam quem consome a desempacotar uma lista. */
    public function testTwoChangesInOneNotificationAreTwoEvents(): void
    {
        $adapter = new ZayataAdapter();
        $decoded = $adapter->decodeIncoming($adapter->encodeOutgoing([
            'packetType' => 0x04,
            'mac' => 'AABBCCDDEEFF',
            'tlv' => [0x8132 => ['value' => "\x07"], 0x8135 => ['value' => "\x06"]],
        ]));

        $changes = [];
        foreach ((new DeviceEventDecoder())->decode(ZayataFixtures::session(), $decoded) as $event) {
            if ($event['feature'] === 'medication_alarm_change') {
                $changes[] = $event['value'];
            }
        }

        self::assertSame([
            ['slot' => 2, 'state' => 'taken'],
            ['slot' => 5, 'state' => 'missed'],
        ], $changes);
    }
}
