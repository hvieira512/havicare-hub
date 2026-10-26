<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Moko;

use Hub\Domain\DiaperSensitivity;
use Hub\Domain\DiaperSensitivityLookup;
use Hub\Ingress\Mqtt\Moko\MokoBridge;
use Hub\Ingress\Mqtt\Moko\MokoGatewayOptions;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\MutableDiaperSensitivity;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** O `change_required` do MONIT MECS-PRO pelo caminho dos eventos, onde um alarme de primeira observação se pode perder. */
final class BridgeMonitAlarmTest extends TestCase
{
    private const GATEWAY = 'd48c49f7909c';
    private const SENSOR = 'eec5000202f9';

    public function testFirstObservationOfAWetSensorStillRaisesTheAlarm(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));

        $alarms = $this->alarms($mqtt);
        self::assertCount(1, $alarms);
        self::assertNull(
            $alarms[0]['payload']['data']['previousState'],
            'A first observation has no previous state, and must say so rather than be dropped.'
        );
    }

    public function testUnchangedConditionRaisesTheAlarmOnlyOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        // Três leituras molhadas distintas: a deduplicação de observações cruas é por
        // payload, por isso estas são três observações reais e não uma repetição.
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required', battery: 79));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required', battery: 78));

        self::assertCount(1, $this->alarms($mqtt));
    }

    public function testTransitionFromAttentionCarriesThePreviousState(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('attention'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));

        $alarms = $this->alarms($mqtt);
        self::assertCount(1, $alarms);
        self::assertSame('attention', $alarms[0]['payload']['data']['previousState']);
    }

    public function testRecoveringAndWettingAgainRaisesASecondAlarm(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('clean'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required', battery: 79));

        $alarms = $this->alarms($mqtt);
        self::assertCount(2, $alarms);
        self::assertNull($alarms[0]['payload']['data']['previousState']);
        self::assertSame('clean', $alarms[1]['payload']['data']['previousState']);
    }

    public function testACleanFirstObservationRaisesNoAlarm(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage($this->topic(), $this->scan('clean'));

        self::assertSame([], $this->alarms($mqtt));
        self::assertSame(
            ['proximity', 'battery', 'diaper_moisture', 'diaper_moisture_level', 'diaper_condition'],
            array_column($mqtt->telemetry, 'type')
        );
    }

    public function testTighteningTheSensitivityRaisesTheAlarmForTheSameReading(): void
    {
        // Três canais molhados são `attention` no preset normal. Apertar a sensibilidade
        // torna a mesma leitura numa muda necessária, e o alarme tem de tocar.
        $mqtt = new RecordingHubMqttBridge();
        $sensitivity = new MutableDiaperSensitivity();
        $bridge = $this->bridge($mqtt, $sensitivity);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('three_wet'));
        self::assertCount(0, $this->alarms($mqtt), 'No preset normal isto e attention.');

        $sensitivity->settings = DiaperSensitivity::PRESETS['high'];
        $bridge->handleReceivedMessage($this->topic(), $this->scan('three_wet', battery: 79));

        $alarms = $this->alarms($mqtt);
        self::assertCount(1, $alarms);
        self::assertSame('attention', $alarms[0]['payload']['data']['previousState']);
    }

    public function testLooseningAndTighteningAgainDoesNotSwallowTheAlarm(): void
    {
        // A sensibilidade vai no valor do estado e não na chave: voltar a um preset já usado
        // tem de ver a transição.
        $mqtt = new RecordingHubMqttBridge();
        $sensitivity = new MutableDiaperSensitivity();
        $bridge = $this->bridge($mqtt, $sensitivity);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));
        self::assertCount(1, $this->alarms($mqtt));

        $sensitivity->settings = DiaperSensitivity::PRESETS['low'];
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required', battery: 79));
        self::assertCount(1, $this->alarms($mqtt), 'Com menos alertas a mesma leitura e attention.');

        $sensitivity->settings = DiaperSensitivity::PRESETS['normal'];
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required', battery: 78));

        $alarms = $this->alarms($mqtt);
        self::assertCount(2, $alarms, 'Voltar ao preset anterior tem de reavaliar, nao recordar.');
        self::assertSame('attention', $alarms[1]['payload']['data']['previousState']);
    }

    /** Húmida mas sem precisar de troca é um alerta: convém ir ver. */
    public function testAWetDiaperRaisesTheCheckAlertOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('clean'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('attention'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('attention', battery: 79));

        $checks = $this->events($mqtt, 'check_required');
        self::assertCount(1, $checks);
        self::assertSame(['previousState' => 'clean'], $checks[0]['payload']['data']);
        self::assertSame([], $this->alarms($mqtt));
    }

    /** Uma fralda que vai molhando dá primeiro o alerta e depois o alarme. */
    public function testWettingStepByStepRaisesTheAlertAndThenTheAlarm(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('clean'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('attention'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));

        self::assertSame(
            ['check_required', 'change_required'],
            array_column($this->events($mqtt, 'check_required', 'change_required'), 'type'),
        );
    }

    /** De seca a suja de uma vez não há alerta pelo meio: só o alarme. */
    public function testWettingAtOnceRaisesOnlyTheAlarm(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $bridge->handleReceivedMessage($this->topic(), $this->scan('clean'));
        $bridge->handleReceivedMessage($this->topic(), $this->scan('change_required'));

        self::assertSame([], $this->events($mqtt, 'check_required'));
        self::assertCount(1, $this->alarms($mqtt));
    }

    /** @return list<array<string, mixed>> */
    private function events(RecordingHubMqttBridge $mqtt, string ...$types): array
    {
        return array_values(array_filter(
            $mqtt->events,
            static fn(array $event): bool => in_array($event['type'] ?? '', $types, true)
        ));
    }

    /**
     * Só os alarmes da fralda, sem o `device.connected` do gateway.
     *
     * @return list<array<string, mixed>>
     */
    private function alarms(RecordingHubMqttBridge $mqtt): array
    {
        return array_values(array_filter(
            $mqtt->events,
            static fn(array $event): bool => ($event['type'] ?? '') === 'change_required'
        ));
    }

    private function topic(): string
    {
        return 'havicare-hub/null/0/gw/' . self::GATEWAY . '/raw';
    }

    private function scan(string $condition, int $battery = 80): string
    {
        return IngressFixtures::monitScan(self::GATEWAY, self::SENSOR, $condition, $battery);
    }

    private function bridge(RecordingHubMqttBridge $mqtt, ?DiaperSensitivityLookup $sensitivity = null): MokoBridge
    {
        return new MokoBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::gateway('MKGW3'),
                self::SENSOR => IngressFixtures::diaperSensor(),
            ]),
            $mqtt,
            IngressFixtures::links(),
            new ArrayObservationStateStore(),
            options: new MokoGatewayOptions(diaperSensitivity: $sensitivity),
        );
    }
}
