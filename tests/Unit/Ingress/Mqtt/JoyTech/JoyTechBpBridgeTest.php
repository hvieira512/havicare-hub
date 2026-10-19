<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\JoyTech;

use Hub\Device\PendingDownlinkQueue;
use Hub\Ingress\Mqtt\JoyTech\BloodPressureHistoryMarks;
use Hub\Ingress\Mqtt\JoyTech\JoyTechFrame;
use Hub\Ingress\Mqtt\JoyTech\JoyTechBpBridge;
use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\OneShotDownlinkQueue;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Do gateway ao MQTT, com as tramas que o DBP-6292B mandou à sonda a 2026-10-16. */
final class JoyTechBpBridgeTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const MONITOR = '748b34837b1e';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** 136/73 mmHg, 76 bpm, sem arritmia, 2026-10-16 14:51, grupo 1. */
    private const LIVE = 'faaaaaaf00168300880049 4c001a0a100e33012cf55f';
    /** 123/87 mmHg, 89 bpm, 2026-05-13 09:41, grupo 2. */
    private const MAY = 'faaaaaaf0016830 07b0057590 01a050d092902 24f55f';
    /** 142/79 mmHg, 88 bpm, com arritmia, 2021-01-01 00:01: o relógio estava por acertar. */
    private const UNSET_CLOCK = 'faaaaaaf001683008e004f58ff150101000101e6f55f';
    private const MEMORY_END = 'faaaaaaf000a8b95f55f';

    private float $now;

    private BloodPressureHistoryMarks $marks;

    private static int $offset = 60;

    protected function setUp(): void
    {
        $this->now = (float)strtotime('2026-10-16T13:51:40Z');
        $this->marks = new BloodPressureHistoryMarks();
        self::$offset = 60;
    }

    /** O aparelho não pede a hora: acerta-se a cada ligação, e logo a seguir lê-se a memória. */
    public function testTheSessionPutsItOnlineSetsTheClockAndReadsTheMemory(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt)->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true]));

        self::assertSame('online', $mqtt->statuses[0]['payload']['state']);
        self::assertSame(['calibrateClock', 'readBloodPressureHistory'], array_column(array_column($mqtt->gatewayCommands, 'payload'), 'operation'));
        self::assertStringStartsWith('faaaaaaf000f04', $mqtt->gatewayCommands[0]['payload']['payload']['writes'][0]['hex']);
    }

    /** O resultado ao vivo vem 6 vezes, de 2 em 2 s, e sai uma só, com a hora de chegada. */
    public function testALiveResultLeavesOnceWithItsArrivalTime(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        for ($i = 0; $i < 6; $i++) {
            $bridge->handleReceivedMessage(self::TOPIC, self::frame(self::LIVE));
            $this->now += 2;
        }

        $pressures = self::telemetry($mqtt, 'blood_pressure');
        self::assertSame([['systolicMmHg' => 136, 'diastolicMmHg' => 73, 'irregularHeartbeat' => false, 'memoryGroup' => 1]], array_column($pressures, 'data'));
        self::assertSame('2026-10-16T13:51:40Z', $pressures[0]['occurredAt']);
        self::assertSame([['bpm' => 76]], array_column(self::telemetry($mqtt, 'heart_rate'), 'data'));
    }

    /**
     * Da memória sai com a hora do aparelho e `fromHistory`. As de antes de 2025 têm a data
     * errada e ficam.
     */
    public function testTheMemoryLeavesDatedByTheMonitor(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        $this->readMemory($bridge, [self::UNSET_CLOCK, self::MAY]);

        $pressures = self::telemetry($mqtt, 'blood_pressure');
        self::assertSame([['systolicMmHg' => 123, 'diastolicMmHg' => 87, 'irregularHeartbeat' => false, 'memoryGroup' => 2, 'fromHistory' => true]], array_column($pressures, 'data'));
        self::assertSame('2026-05-13T08:41:00Z', $pressures[0]['occurredAt']);
        self::assertSame([['bpm' => 89, 'fromHistory' => true]], array_column(self::telemetry($mqtt, 'heart_rate'), 'data'));
    }

    /** Ler a memória não a apaga: a segunda leitura, mesmo depois de reiniciar o hub, não repete nada. */
    public function testASecondReadOfTheMemoryRepeatsNothing(): void
    {
        $this->readMemory($this->bridge(new RecordingHubMqttBridge()), [self::MAY]);
        $this->now += 60;

        $mqtt = new RecordingHubMqttBridge();
        $this->readMemory($this->bridge($mqtt), [self::MAY]);

        self::assertSame([], $mqtt->telemetry);
    }

    /** O que saiu ao vivo também está na memória, e a leitura seguinte não o traz outra vez. */
    public function testALiveResultIsNotRepeatedByTheNextRead(): void
    {
        $bridge = $this->bridge(new RecordingHubMqttBridge());
        $bridge->handleReceivedMessage(self::TOPIC, self::frame(self::LIVE));
        $this->now += 60;

        $mqtt = new RecordingHubMqttBridge();
        $this->readMemory($this->bridge($mqtt), [self::LIVE]);

        self::assertSame([], $mqtt->telemetry);
    }

    /**
     * Cada ligação começa com uma medição, que chega antes da memória: o que se mediu sem gateway
     * antes dela continua a entrar.
     */
    public function testWhatWasMeasuredOfflineBeforeALiveResultStillComesFromTheMemory(): void
    {
        $later = self::reading(118, 79, 70, 26, 10, 16, 15, 0, 1);
        $bridge = $this->bridge(new RecordingHubMqttBridge());
        $this->now = (float)strtotime('2026-10-16T14:00:56Z');
        $bridge->handleReceivedMessage(self::TOPIC, self::frame($later));
        $this->now += 60;

        $mqtt = new RecordingHubMqttBridge();
        $this->readMemory($this->bridge($mqtt), [self::LIVE, $later]);

        self::assertSame([136], array_column(array_column(self::telemetry($mqtt, 'blood_pressure'), 'data'), 'systolicMmHg'));
    }

    /** A memória é um anel: um registo mais recente à frente não faz esquecer os ao vivo que vêm atrás. */
    public function testTheMemoryOrderDoesNotBringBackLiveResults(): void
    {
        $earlier = self::reading(121, 80, 66, 26, 10, 16, 14, 30, 1);
        $later = self::reading(118, 79, 70, 26, 10, 16, 15, 0, 1);
        $bridge = $this->bridge(new RecordingHubMqttBridge());
        $this->now = (float)strtotime('2026-10-16T13:30:56Z');
        $bridge->handleReceivedMessage(self::TOPIC, self::frame($earlier));
        $this->now = (float)strtotime('2026-10-16T14:00:56Z');
        $bridge->handleReceivedMessage(self::TOPIC, self::frame($later));
        $this->now += 60;

        $mqtt = new RecordingHubMqttBridge();
        $this->readMemory($this->bridge($mqtt), [$later, $earlier]);

        self::assertSame([], $mqtt->telemetry);
    }

    /** A ligação cai a meio da memória: o que ficou por ler entra na seguinte, e o que já saiu não se repete. */
    public function testAnInterruptedReadLosesNothing(): void
    {
        [$first, $second, $third] = [
            self::reading(121, 80, 70, 26, 9, 1, 9, 0, 1),
            self::reading(122, 81, 71, 26, 9, 2, 9, 0, 1),
            self::reading(123, 82, 72, 26, 9, 3, 9, 0, 1),
        ];
        $bridge = $this->bridge(new RecordingHubMqttBridge());
        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true]));
        $this->now += 0.1;
        $bridge->handleReceivedMessage(self::TOPIC, self::frame($third));
        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => false]));
        $this->now += 60;

        $mqtt = new RecordingHubMqttBridge();
        $this->readMemory($this->bridge($mqtt), [$third, $second, $first]);

        self::assertSame([122, 121], array_column(array_column(self::telemetry($mqtt, 'blood_pressure'), 'data'), 'systolicMmHg'));
    }

    /** Um registo da memória que chegue fora da leitura não passa por medição nova. */
    public function testAnOldRecordOutsideARead(): void
    {
        $april = self::reading(119, 77, 66, 26, 4, 2, 9, 0, 2);
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $this->readMemory($bridge, [self::MAY]);
        $this->now += 60;

        $bridge->handleReceivedMessage(self::TOPIC, self::frame($april));
        $bridge->handleReceivedMessage(self::TOPIC, self::frame(self::MAY));

        self::assertCount(1, self::telemetry($mqtt, 'blood_pressure'));
    }

    /** Na mudança de hora o aparelho guarda a mesma hora local, e a memória não volta a sair. */
    public function testTheClockChangeDoesNotBringTheMemoryBack(): void
    {
        $this->readMemory($this->bridge(new RecordingHubMqttBridge()), [self::MAY]);
        $this->now += 86400;
        self::$offset = 0;

        $mqtt = new RecordingHubMqttBridge();
        $this->readMemory($this->bridge($mqtt), [self::MAY]);

        self::assertSame([], self::telemetry($mqtt, 'blood_pressure'));
    }

    /** O hub reiniciou com o aparelho ligado: o primeiro resultado refaz a sessão, e a memória pede-se. */
    public function testARestartedHubPicksTheSessionUpFromTheFrames(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $bridge->handleReceivedMessage(self::TOPIC, self::frame(self::LIVE));
        $this->now += 16;

        $bridge->dispatchQueued();

        self::assertSame(['readBloodPressureHistory'], array_column(array_column($mqtt->gatewayCommands, 'payload'), 'operation'));
    }

    /** A medição em curso ignora a leitura da memória; o resultado que chega a seguir é ao vivo. */
    public function testAResultLongAfterTheReadIsLive(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true]));
        $this->now += 30;

        $bridge->handleReceivedMessage(self::TOPIC, self::frame(self::LIVE));

        self::assertArrayNotHasKey('fromHistory', self::telemetry($mqtt, 'blood_pressure')[0]['data']);
    }

    /**
     * O aparelho só se liga quando se carrega em Start, e a leitura ao ligar chega a meio da
     * medição: pede-se outra vez depois das repetições do resultado.
     */
    public function testTheMemoryIsReadAgainAfterTheLiveResult(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true]));
        $this->now += 30;
        $bridge->handleReceivedMessage(self::TOPIC, self::frame(self::LIVE));

        $this->now += 10;
        $bridge->dispatchQueued();
        self::assertCount(2, $mqtt->gatewayCommands);

        $this->now += 5;
        $bridge->dispatchQueued();
        $bridge->dispatchQueued();
        self::assertSame(['calibrateClock', 'readBloodPressureHistory', 'readBloodPressureHistory'], array_column(array_column($mqtt->gatewayCommands, 'payload'), 'operation'));
    }

    /** Uma leitura que já chegou ao fim nesta ligação não se repete. */
    public function testAMemoryAlreadyReadInTheSessionIsNotReadAgain(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $this->readMemory($bridge, []);
        $this->now += 30;
        $bridge->handleReceivedMessage(self::TOPIC, self::frame(self::LIVE));

        $this->now += 20;
        $bridge->dispatchQueued();

        self::assertCount(2, $mqtt->gatewayCommands);
    }

    /**
     * O pedido da dashboard fecha-o o gateway, que só responde depois do `0x8B` dele; o fim de uma
     * leitura automática não fecha o pedido de ninguém.
     */
    public function testOnlyTheGatewayClosesTheRequestedRead(): void
    {
        $marked = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->expects(self::never())->method('markCommandReply');
        $store->method('markCommand')->willReturnCallback(
            static function (string $imei, string $id, array $fields) use (&$marked): void {
                $marked[] = [$id, $fields['status']];
            }
        );
        $queue = new OneShotDownlinkQueue('readBloodPressureHistory', ['id' => 'op-1', 'command' => 'readBloodPressureHistory', 'payload' => []]);
        $bridge = $this->bridge(new RecordingHubMqttBridge(), $queue, $store);

        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true]));
        $bridge->handleReceivedMessage(self::TOPIC, self::frame(self::MEMORY_END));
        $bridge->handleReceivedMessage(self::TOPIC, self::message('command_result', ['dedupeKey' => 'test-dedupe', 'ok' => true]));

        self::assertSame([['op-1', 'waiting'], ['op-1', 'acked']], $marked);
    }

    /**
     * Duas leituras seguidas: os registos que a primeira já trouxe são repetições, mas continuam a
     * ser da memória, e o que vem depois deles também.
     */
    public function testRepeatedRecordsKeepTheReadOpen(): void
    {
        $october = self::reading(131, 84, 72, 26, 10, 1, 9, 0, 1);
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $this->readMemory($bridge, [self::UNSET_CLOCK, self::MAY]);

        $this->now += 5;
        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true]));
        foreach ([self::UNSET_CLOCK, self::MAY, $october] as $hex) {
            $this->now += 2.6;
            $bridge->handleReceivedMessage(self::TOPIC, self::frame($hex));
        }

        $last = self::telemetry($mqtt, 'blood_pressure')[1]['data'];
        self::assertSame(131, $last['systolicMmHg']);
        self::assertTrue($last['fromHistory'] ?? false);
    }

    public function testAQueuedReadLeavesWhenTheMonitorConnects(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new OneShotDownlinkQueue('readBloodPressureHistory', ['id' => 'op-1', 'command' => 'readBloodPressureHistory', 'payload' => []]);

        $this->bridge($mqtt, $queue)->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true]));

        // A leitura pedida substitui a automática: o aparelho não lê a memória duas vezes.
        $commands = array_column($mqtt->gatewayCommands, 'payload');
        self::assertSame(['calibrateClock', 'readBloodPressureHistory'], array_column($commands, 'operation'));
        self::assertSame('op-1', $commands[1]['commandId']);
    }

    /** O gateway entrega o termómetro pela mesma fonte: não é um tensiómetro por autorizar. */
    public function testAThermometerIsLeftToItsOwnBridge(): void
    {
        $store = $this->createMock(DeviceStoreContract::class);
        $store->expects(self::never())->method('recordRejectedDevice');
        $message = json_encode([
            'gatewayId' => self::GATEWAY,
            'source' => 'joytech-node',
            'kind' => 'frame',
            'device' => ['mac' => '748b34830e5b', 'model' => 'DET-3018 '],
            'payload' => ['char' => 'fff1', 'hex' => 'faaaaaaf000a0610f55f'],
        ], JSON_THROW_ON_ERROR);

        $this->bridge(new RecordingHubMqttBridge(), store: $store)->handleReceivedMessage(self::TOPIC, $message);
    }

    public function testAMonitorNotLinkedToTheGatewayIsIgnored(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $this->bridge($mqtt, links: false)->handleReceivedMessage(self::TOPIC, self::frame(self::LIVE));

        self::assertSame([], $mqtt->telemetry);
    }

    /** @param list<string> $records */
    private function readMemory(JoyTechBpBridge $bridge, array $records): void
    {
        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true]));
        foreach ($records as $hex) {
            $this->now += 0.1;
            $bridge->handleReceivedMessage(self::TOPIC, self::frame($hex));
        }
        $bridge->handleReceivedMessage(self::TOPIC, self::frame(self::MEMORY_END));
    }

    /** @return list<array<string, mixed>> */
    private static function telemetry(RecordingHubMqttBridge $mqtt, string $type): array
    {
        return array_values(array_map(
            static fn (array $entry): array => $entry['payload'],
            array_filter($mqtt->telemetry, static fn (array $entry): bool => ($entry['payload']['type'] ?? null) === $type),
        ));
    }

    private static function reading(int $systolic, int $diastolic, int $pulse, int ...$timeAndGroup): string
    {
        return JoyTechFrame::encode(0x83, pack('nnCC', $systolic, $diastolic, $pulse, 0) . pack('C*', ...$timeAndGroup));
    }

    private static function frame(string $hex): string
    {
        return self::message('frame', ['char' => 'fff1', 'hex' => str_replace(' ', '', $hex)]);
    }

    /** Uma sessão de que não se ouve nada há 2 minutos acabou: a ordem espera pela ligação seguinte. */
    public function testASilentSessionEnds(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new \Hub\Device\RedisPendingDownlinkQueue(new \Tests\Support\Doubles\InMemoryRedisClient());
        $bridge = $this->bridge($mqtt, $queue);
        $bridge->handleReceivedMessage(self::TOPIC, self::message('session', ['connected' => true]));
        $mqtt->gatewayCommands = [];

        $this->now += 121;
        $queue->enqueue(self::MONITOR, 'readBloodPressureHistory', ['command' => 'readBloodPressureHistory'], 300);
        $bridge->dispatchQueued();

        self::assertSame([], $mqtt->gatewayCommands);
    }

    /** @param array<string, mixed> $payload */
    private static function message(string $kind, array $payload): string
    {
        return json_encode([
            'gatewayId' => self::GATEWAY,
            'source' => 'joytech-node',
            'kind' => $kind,
            'device' => ['mac' => self::MONITOR, 'model' => 'DBP-6292B'],
            'payload' => $payload,
            'tzOffsetMinutes' => self::$offset,
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(
        RecordingHubMqttBridge $mqtt,
        ?PendingDownlinkQueue $queue = null,
        ?DeviceStoreContract $store = null,
        bool $links = true,
    ): JoyTechBpBridge {
        return new JoyTechBpBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
                self::MONITOR => IngressFixtures::device('JoyTech', 'HC-6292B', 'blood_pressure_monitor'),
            ]),
            $mqtt,
            IngressFixtures::links($links),
            $queue,
            'havicare-hub/null/0/gw/+/raw',
            null,
            $store,
            fn (): float => $this->now,
            marks: $this->marks,
        );
    }
}
