<?php

declare(strict_types=1);

namespace Tests\Unit\Hub;

use Hub\Device\DeviceEventDecoder;
use Hub\Device\DeviceSession;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O retrato dos nove alarmes e a mudança de um são coisas diferentes.
 *
 * Os mesmos bytes -- as TAGs `0x8131`--`0x8139` -- chegam por dois caminhos com naturezas
 * opostas. A resposta ao `0x07` traz os nove e é uma **leitura**: o estado num instante, que
 * se pediu. A notificação `0x04` traz o alarme que mudou e mais nada, e é um
 * **acontecimento**: alguma coisa acabou de suceder.
 *
 * Viajavam ambos como `medication_alarm_status`, distinguidos por uma bandeira
 * `complete: false` -- um remendo a dizer «isto não é bem o que o nome diz». E o que isso
 * custava era concreto: uma dose falhada não gera `medication_intake` nenhum, porque não
 * houve toma a registar, e o único sinal dela é esta mudança de estado. Como telemetria, saía
 * a QoS 0.
 */
final class PillDispenserDoseChangeTest extends TestCase
{
    /** A resposta ao `0x07` continua a ser a leitura dos nove, com os totais. */
    public function testTheStatusReplyIsStillTheReadingOfTheNine(): void
    {
        $byFeature = $this->decode(0x87, [0x8131 => "\x07", 0x8133 => "\x06"]);

        self::assertArrayNotHasKey('medication_alarm_change', $byFeature);
        self::assertSame(1, $byFeature['medication_alarm_status']['takenCount'] ?? null);
        self::assertSame(1, $byFeature['medication_alarm_status']['missedCount'] ?? null);
        self::assertCount(2, $byFeature['medication_alarm_status']['alarms'] ?? []);
    }

    /**
     * A notificação passa a ser evento próprio, com o alarme e o estado novo.
     *
     * Sem contagens: uma notificação traz um alarme, e um total tirado de um alarme não é
     * total nenhum -- era o que punha «1 tomada» por cima de um cartão que dizia duas falhas.
     */
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

    /** E a bandeira que os distinguia deixa de ser precisa. */
    public function testTheCompleteFlagIsGone(): void
    {
        self::assertArrayNotHasKey(
            'complete',
            $this->decode(0x87, [0x8131 => "\x07"])['medication_alarm_status'] ?? [],
        );
    }

    /**
     * Uma notificação com mais do que um alarme dá um evento por alarme.
     *
     * Nunca se observou o aparelho a mandar dois, mas se mandar, dois acontecimentos numa
     * mensagem obrigavam quem consome a desempacotar uma lista para ler um facto.
     *
     * @return void
     */
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
