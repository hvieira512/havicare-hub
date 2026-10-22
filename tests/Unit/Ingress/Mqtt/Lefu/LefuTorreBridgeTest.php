<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Lefu;

use Hub\Device\RedisPendingDownlinkQueue;
use Hub\Ingress\Mqtt\JoyTech\RecentMeasurementKeys;
use Hub\Ingress\Mqtt\Lefu\LefuTorreBridge;
use Hub\Ingress\Mqtt\Lefu\ScaleOnlineUsers;
use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Do gateway ao MQTT, com as tramas da pesagem real em que a FC foi medida. */
final class LefuTorreBridgeTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const SCALE = 'cfe908067329';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    private float $now = 1000.0;

    public function testTheSessionPutsTheScaleOnlineWithItsBatteryAndSetsTheClock(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        IngressFixtures::relay($this->bridge($mqtt), self::TOPIC, self::message('session', ['connected' => true, 'battery' => 46, 'mtu' => 61]));

        self::assertSame('online', $mqtt->statuses[0]['payload']['state']);
        self::assertSame(['percent' => 46], self::telemetry($mqtt, 'battery')[0]['data']);
        self::assertSame('calibrateClock', $mqtt->gatewayCommands[0]['payload']['operation']);
        self::assertStringStartsWith('0406', $mqtt->gatewayCommands[0]['payload']['payload']['writes'][0]['hex']);
    }

    public function testACompleteWeighingPublishesWeightHeartRateAndImpedanceOnce(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        foreach (['010d6500942500000032a00f64330e', '010d6500942500000032a00f64330e', '010da500942500 4a0032a00f64330e'] as $hex) {
            IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', $hex));
        }
        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', '020a180210536f00ff16db00'));
        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));
        $this->now += 0.3;
        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));

        self::assertSame([['weightKg' => 96.2]], array_column(self::telemetry($mqtt, 'weight'), 'data'));
        self::assertSame([['bpm' => 74]], array_column(self::telemetry($mqtt, 'heart_rate'), 'data'));
        self::assertSame([[
            'impedance50KhzOhm' => 400.0,
            'impedance100KhzOhm' => 363.5,
            'electrodes' => 'four_dual_frequency',
            'encodedImpedance50Khz' => 7295760,
            'encodedImpedance100Khz' => 14358271,
        ]], array_column(self::telemetry($mqtt, 'body_impedance'), 'data'));
        // Três estados diferentes; a trama intermédia repetida não sai.
        self::assertSame(['measuring', 'measured'], array_values(array_unique(array_column(array_column(self::telemetry($mqtt, 'weighing_status'), 'data'), 'heartRatePhase'))));
        self::assertCount(2, self::telemetry($mqtt, 'weighing_status'));
    }

    /** O peso oscila enquanto estabiliza: o estado só sai quando muda a fase. */
    public function testTheStatusLeavesOnlyWhenThePhaseChanges(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        foreach (['010d0100750300000000000000000000', '010d0100700300000000000000000000', '010d0100750300000000000000000000', '010d0500750300000000000000000000'] as $hex) {
            IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', $hex));
        }

        self::assertSame(['settling', 'stable'], array_column(array_column(self::telemetry($mqtt, 'weighing_status'), 'data'), 'weightPhase'));
    }

    /** Mau contacto: a impedância em claro vem implausível, e a falha fica no estado da pesagem. */
    public function testAFailedImpedanceStaysInTheWeighingStatusAndIsNotPublishedAsAReading(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        IngressFixtures::relay($this->bridge($mqtt), self::TOPIC, self::frame('fff3', '010d3580fd25000000320000640000'));

        self::assertSame([], self::telemetry($mqtt, 'body_impedance'));
        self::assertSame('failed', self::telemetry($mqtt, 'weighing_status')[0]['data']['impedancePhase']);
        self::assertSame([['weightKg' => 97.25]], array_column(self::telemetry($mqtt, 'weight'), 'data'));
    }

    /** Sem medição, não servem a ninguém: o movimento, o histórico novo, a escolha no aparelho e a falha por membro. */
    public function testFramesWithoutAMeasurementAreNotPublished(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);

        foreach (['0400', '0500', '060102', '1603000002'] as $hex) {
            IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', $hex));
        }

        self::assertSame([], $mqtt->telemetry);
    }

    public function testTheScaleAnswerClosesTheCommandAndAReadIsReportedConfiguration(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $replies = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('markCommandReply')->willReturnCallback(
            static function (string $imei, string $type, mixed $ident, string $ref, ?bool $accepted) use (&$replies): void {
                $replies[] = [$type, $accepted];
            }
        );
        $bridge = $this->bridge($mqtt, null, $store);

        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff1', '06020101'));
        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff1', '07020200'));

        self::assertSame([['torre_06_set', false], ['torre_07_read', true]], $replies);
        self::assertSame(['settings' => ['impedance_measurement' => ['enabled' => true]]], self::telemetry($mqtt, 'device_config')[0]['data']);
    }

    /** A razão da recusa chega ao comando, para o ecrã a dizer. */
    public function testTheScaleRefusalReasonReachesTheCommand(): void
    {
        $errors = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('markCommandReply')->willReturnCallback(
            static function (string $imei, string $type, mixed $ident, string $ref, ?bool $accepted, string $error = '') use (&$errors): void {
                $errors[] = [$type, $accepted, $error];
            }
        );

        IngressFixtures::relay($this->bridge(new RecordingHubMqttBridge(), null, $store), self::TOPIC, self::frame('fff2', '05020204'));

        self::assertSame([['torre_user_02', false, 'memory_full']], $errors);
    }

    /** Quem a balança aceitou em linha é dono da pesagem, mesmo que a lista do hub diga outro. */
    public function testTheWeighingBelongsToTheUserTheScaleAccepted(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('commands')->willReturn([
            ['status' => 'waiting', 'nativeType' => 'selectScaleUser', 'payload' => ['id' => 'u-2']],
        ]);
        $users = static fn (string $imei): array => [
            'items' => [['id' => 'u-0a1b2c3d', 'name' => 'Hugo'], ['id' => 'u-2', 'name' => 'Ana']],
            'onlineUserId' => 'u-0a1b2c3d',
        ];
        $bridge = $this->bridge($mqtt, null, $store, users: $users);

        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff2', '05020700'));
        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));

        self::assertSame('u-2', self::telemetry($mqtt, 'weight')[0]['data']['scaleUserId']);
    }

    /** O hub monta as escritas; o gateway só as faz, e só com sessão aberta. */
    public function testAQueuedSettingLeavesAsWritesWhenTheScaleConnects(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue(self::SCALE, 'heartRateMeasurement', ['id' => 'op-1', 'command' => 'heartRateMeasurement', 'payload' => ['enabled' => true]], 300);

        IngressFixtures::relay($this->bridge($mqtt, $queue), self::TOPIC, self::message('session', ['connected' => true, 'mtu' => 61]));

        $command = $mqtt->gatewayCommands[1]['payload'];
        self::assertSame('heartRateMeasurement', $command['operation']);
        self::assertSame('op-1', $command['commandId']);
        self::assertSame([['char' => 'fff1', 'hex' => '06020100']], $command['payload']['writes']);
    }

    /** Um hub reiniciado com a balança ligada retoma a sessão pela primeira trama, e entrega a fila. */
    public function testAFrameAfterARestartResumesTheSession(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue(self::SCALE, 'heartRateMeasurement', ['id' => 'op-1', 'command' => 'heartRateMeasurement', 'payload' => ['enabled' => true]], 300);
        $bridge = $this->bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', '010d0c00000000000000000000000000'));
        $bridge->dispatchQueued();

        self::assertSame(['heartRateMeasurement'], array_column(array_column($mqtt->gatewayCommands, 'payload'), 'operation'));
    }

    /** Uma sessão de que não se ouve nada há 2 minutos acabou, mesmo sem o `connected: false`. */
    public function testASilentSessionEnds(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new \Hub\Device\RedisPendingDownlinkQueue(new \Tests\Support\Doubles\InMemoryRedisClient());
        $bridge = $this->bridge($mqtt, $queue);
        IngressFixtures::relay($bridge, self::TOPIC, self::message('session', ['connected' => true, 'mtu' => 61]));
        $mqtt->gatewayCommands = [];

        $this->now += 121;
        $queue->enqueue(self::SCALE, 'heartRateMeasurement', ['command' => 'heartRateMeasurement', 'payload' => ['enabled' => true]], 300);
        $bridge->dispatchQueued();

        self::assertSame([], $mqtt->gatewayCommands);
    }

    /** A balança lembra-se de quem está em linha entre ligações: vale o que ela confirmou por último. */
    public function testTheWeighingCarriesTheConfirmedOnlineUserAcrossConnections(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt, users: self::users('u-0a1b2c3d'));

        IngressFixtures::relay($bridge, self::TOPIC, self::message('session', ['connected' => false]));
        IngressFixtures::relay($bridge, self::TOPIC, self::message('session', ['connected' => true, 'mtu' => 61]));
        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));

        self::assertSame([['weightKg' => 96.2, 'scaleUserId' => 'u-0a1b2c3d']], array_column(self::telemetry($mqtt, 'weight'), 'data'));
        self::assertSame('u-0a1b2c3d', self::telemetry($mqtt, 'heart_rate')[0]['data']['scaleUserId']);
    }

    /** Com impedância medida e um utilizador com perfil, a pesagem traz a composição dele. */
    public function testAWeighingWithImpedanceCarriesTheBodyCompositionOfTheOnlineUser(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        IngressFixtures::relay($this->bridge($mqtt, users: self::users('u-0a1b2c3d')), self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));

        $composition = self::telemetry($mqtt, 'body_composition');
        self::assertCount(1, $composition);
        self::assertSame('u-0a1b2c3d', $composition[0]['data']['scaleUserId']);
        self::assertSame(29.3, $composition[0]['data']['bmi']);
        self::assertSame(self::telemetry($mqtt, 'weight')[0]['occurredAt'], $composition[0]['occurredAt']);
    }

    /** A composição usa a idade que a pessoa tem no dia da pesagem. */
    public function testTheCompositionUsesTheAgeOnTheDayOfTheWeighing(): void
    {
        $this->now = (float)strtotime('2026-10-19T10:00:00Z');
        $frame = self::frame('fff3', '010da580942500 4a0032a00f64330e');
        $byAge = new RecordingHubMqttBridge();
        IngressFixtures::relay($this->bridge($byAge, users: self::users('u-0a1b2c3d')), self::TOPIC, $frame);
        $byBirthDate = new RecordingHubMqttBridge();
        IngressFixtures::relay($this->bridge($byBirthDate, users: self::users('u-0a1b2c3d', ['birthDate' => '2003-05-10'])), self::TOPIC, $frame);

        self::assertSame(self::telemetry($byAge, 'body_composition')[0]['data'], self::telemetry($byBirthDate, 'body_composition')[0]['data']);
    }

    /** Um hub reiniciado com a balança ligada só a vê pela primeira trama, e é aí que ela fica ligada. */
    public function testAFrameWithoutASessionMarksTheScaleOnline(): void
    {
        $mqtt = new RecordingHubMqttBridge();

        IngressFixtures::relay($this->bridge($mqtt), self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));

        self::assertSame(['online'], array_column(array_column($mqtt->statuses, 'payload'), 'state'));
    }

    public function testWithoutAnOnlineUserTheWeighingHasNoOwnerNorComposition(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        IngressFixtures::relay($this->bridge($mqtt, users: self::users('')), self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));

        self::assertSame([['weightKg' => 96.2]], array_column(self::telemetry($mqtt, 'weight'), 'data'));
        self::assertSame([], self::telemetry($mqtt, 'body_composition'));
    }

    /** O histórico sai pelas mesmas capacidades, com a data da pesagem e não a da leitura. */
    public function testTheHistoryLeavesAsWeighingsDatedByTheScale(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        IngressFixtures::relay($this->bridge($mqtt), self::TOPIC, self::frame('fff2', bin2hex(str_pad('u-0a1b2c3d', 64, "\0"))
            . 'ad3744691c074f0259746f00150fd1661e00290e'
            // Anterior a 2023-02-03: o SDK descarta-o, e o hub também.
            . '0000000064000000000000000000000000000000'));

        $weights = self::telemetry($mqtt, 'weight');
        self::assertCount(1, $weights);
        self::assertSame('2025-12-18T17:19:41Z', $weights[0]['occurredAt']);
        self::assertSame(['weightKg' => 18.2, 'scaleUserId' => 'u-0a1b2c3d', 'fromHistory' => true], $weights[0]['data']);
        self::assertSame(79, self::telemetry($mqtt, 'heart_rate')[0]['data']['bpm']);
        self::assertSame(386.1, self::telemetry($mqtt, 'body_impedance')[0]['data']['impedance50KhzOhm']);
    }

    /** Ler o histórico outra vez não repete o que já saiu, e uma pesagem sem impedância não a inventa a zero. */
    public function testReadingTheHistoryAgainRepeatsNothingAndAWeighingWithoutImpedanceHasNone(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        $history = self::frame('fff2', bin2hex(str_pad('u-0a1b2c3d', 64, "\0"))
            . 'ad3744691c074f0259746f00150fd1661e00290e'
            // Descalço não: a balança guardou o peso e o pulso, e as impedâncias a zero.
            . 'b13744691c074f02' . str_repeat('00', 12));

        IngressFixtures::relay($bridge, self::TOPIC, $history);
        IngressFixtures::relay($bridge, self::TOPIC, $history);

        self::assertCount(2, self::telemetry($mqtt, 'weight'));
        self::assertCount(1, self::telemetry($mqtt, 'body_impedance'));
    }

    /** A impedância cifrada é de quem estava em cima: com a balança vazia, já não é de ninguém. */
    public function testTheEncodedImpedanceDoesNotPassToTheNextPerson(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', '020a180210536f00ff16db00'));
        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', '010d0c00000000000000000000000000'));
        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));

        self::assertArrayNotHasKey('encodedImpedance50Khz', self::telemetry($mqtt, 'body_impedance')[0]['data']);
    }

    /** Uma ligação nova começa sem estado: o primeiro da pesagem seguinte sai, mesmo igual ao último. */
    public function testEachConnectionStartsItsOwnWeighingStatus(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $bridge = $this->bridge($mqtt);
        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', '010d0c00000000000000000000000000'));
        IngressFixtures::relay($bridge, self::TOPIC, self::message('session', ['connected' => false]));
        IngressFixtures::relay($bridge, self::TOPIC, self::frame('fff3', '010d0c00000000000000000000000000'));

        self::assertCount(2, self::telemetry($mqtt, 'weighing_status'));
    }

    public function testAScaleOfAnotherSourceOrNotLinkedIsIgnored(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        IngressFixtures::relay($this->bridge($mqtt, links: false), self::TOPIC, self::frame('fff3', '010da580942500 4a0032a00f64330e'));

        self::assertSame([], $mqtt->telemetry);
    }

    /** @return list<array<string, mixed>> */
    private static function telemetry(RecordingHubMqttBridge $mqtt, string $type): array
    {
        return array_column($mqtt->ofType($type), 'payload');
    }

    private static function frame(string $characteristic, string $hex): string
    {
        return self::message('frame', ['char' => $characteristic, 'hex' => $hex]);
    }

    /** @param array<string, mixed> $payload */
    private static function message(string $kind, array $payload): string
    {
        return json_encode([
            'gatewayId' => self::GATEWAY,
            'source' => 'lefu-torre-node',
            'kind' => $kind,
            'device' => ['mac' => self::SCALE, 'model' => 'CF632', 'firmware' => '005.015'],
            'payload' => $payload,
            'tzOffsetMinutes' => 60,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $age a idade guardada, ou a data de nascimento no lugar dela
     * @return callable(string): array<string, mixed>
     */
    private static function users(string $onlineUserId, array $age = ['ageYears' => 23]): callable
    {
        return static fn (string $imei): array => [
            'items' => [['id' => 'u-0a1b2c3d', 'name' => 'Hugo'] + $age + ['sex' => 'male', 'heightCm' => 181, 'athlete' => false, 'weightKg' => 96]],
            'onlineUserId' => $onlineUserId,
        ];
    }

    private function bridge(
        RecordingHubMqttBridge $mqtt,
        ?RedisPendingDownlinkQueue $queue = null,
        ?DeviceStoreContract $store = null,
        bool $links = true,
        ?callable $users = null,
    ): LefuTorreBridge {
        return new LefuTorreBridge(
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'BLE Gateway', 'gateway'),
                self::SCALE => IngressFixtures::device('Unique Scales', 'HC-CF632BLE', 'scale'),
            ]),
            $mqtt,
            IngressFixtures::links($links),
            $queue,
            'havicare-hub/null/0/gw/+/raw',
            new ScaleOnlineUsers($redis = new InMemoryRedisClient()),
            new RecentMeasurementKeys($redis, 'hub:lefu:history-seen', 500),
            $store,
            fn (): float => $this->now,
            scaleUsers: $users,
        );
    }
}
