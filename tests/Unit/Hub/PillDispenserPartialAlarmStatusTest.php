<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Uma notificação traz só o alarme que mudou, e as contagens não podem fingir que são totais.
 *
 * O aparelho manda um `0x04` sempre que um alarme muda de estado, e esse pacote traz **um**
 * alarme, não os nove. As contagens eram feitas sobre o que estivesse na trama e o cartão
 * rotulava-as «Tomadas» e «Falhadas»: uma notificação de uma toma substituía um cartão que
 * dizia duas falhas por outro a dizer «1 tomada», e as duas falhas desapareciam do ecrã.
 *
 * Só a resposta a uma consulta de estado (`0x07`) pergunta pelos nove e pode contar. O resto
 * diz o que mudou, e diz que está incompleto.
 */
final class PillDispenserPartialAlarmStatusTest extends TestCase
{
    /** A resposta ao `0x07` pergunta pelos nove, e por isso as contagens valem. */
    public function testAStatusQueryCounts(): void
    {
        $value = $this->alarmStatus(0x87, [
            0x8131 => "\x07",
            0x8132 => "\x06",
            0x8133 => "\x00",
        ]);

        self::assertSame(1, $value['takenCount']);
        self::assertSame(1, $value['missedCount']);
        self::assertTrue($value['complete']);
    }

    /** Uma notificação traz o que mudou, e assume-se incompleta. */
    public function testAChangeNotificationIsMarkedIncomplete(): void
    {
        $value = $this->alarmStatus(0x04, [0x8132 => "\x07"]);

        self::assertFalse($value['complete']);
        self::assertSame([['alarm' => 2, 'state' => 'taken']], $value['alarms']);
        self::assertArrayNotHasKey('takenCount', $value, 'uma trama parcial não conta totais');
        self::assertArrayNotHasKey('missedCount', $value);
    }

    /** O heartbeat também não pergunta pelos nove. */
    public function testAHeartbeatIsAlsoIncomplete(): void
    {
        $value = $this->alarmStatus(0x02, [0x8133 => "\x06"]);

        self::assertFalse($value['complete']);
        self::assertArrayNotHasKey('missedCount', $value);
    }

    /**
     * @param array<int, string> $tlv
     * @return array<string, mixed>
     */
    private function alarmStatus(int $packetType, array $tlv): array
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

        foreach ((new DeviceEventDecoder())->decode($this->session(), $decoded) as $event) {
            if ($event['feature'] === 'medication_alarm_status') {
                return $event['value'];
            }
        }

        self::fail('não saiu estado de alarmes nenhum');
    }

    private function session(): DeviceSession
    {
        return new DeviceSession(
            new PillFakeConnection(),
            'tcp',
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
