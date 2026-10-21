<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\State\DeviceStoreContract;
use Hub\Device\PendingDownlink;
use Hub\Device\PendingDownlinkQueue;
use Tests\Support\Doubles\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Veepoo\VeepooBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** Sai em `device.measurement_failed` e fecha o pedido, senão é reentregue e a pulseira repete uma medição sem valor. */
final class BridgeFailedMeasurementTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** E só esse: as outras medições em fila não sabem nada sobre esta. */
    public function testOnlyTheRequestThatFailedLeavesTheQueue(): void
    {
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $queue->add('measure.oxygen.start');
        $bridge = $this->bridge(new RecordingHubMqttBridge(), $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement([
            'sdkType' => 51,
            'notWear' => true,
        ]));

        self::assertSame(['measure.oxygen.start'], $queue->operations());
    }

    /** Bateria fraca e sensor anómalo são razões do aparelho, e valem o mesmo. */
    public function testADeviceFailureAlsoClosesTheRequest(): void
    {
        $queue = new FakeQueue();
        $queue->add('measure.bloodPressure.start');
        $bridge = $this->bridge(new RecordingHubMqttBridge(), $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement([
            'sdkType' => 18,
            'deviceDetectionInfo' => 'atLowVoltage',
        ]));

        self::assertSame([], $queue->operations());
    }

    /** Um traçado todo a zeros chega em `ecg_wave` sem tipo do SDK, mas o pedido que o originou é sempre o mesmo. */
    public function testAnEcgWithoutSignalClosesTheRequest(): void
    {
        $queue = new FakeQueue();
        $queue->add('measure.ecg.start');
        $bridge = $this->bridge(new RecordingHubMqttBridge(), $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'ecg_wave',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['samples' => [0, 0, 0, 0], 'samplingHz' => 500],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $queue->operations());
    }

    /** Estar ocupada é passageiro: a medição a decorrer acaba e o pedido continua de pé. */
    public function testABusyBandKeepsTheRequestQueued(): void
    {
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $bridge = $this->bridge(new RecordingHubMqttBridge(), $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement([
            'sdkType' => 51,
            'deviceBusy' => true,
        ]));

        self::assertSame(['measure.heartRate.start'], $queue->operations());
    }

    /** O ecrã tem de mostrar o pedido por falhado, e com a razão. */
    public function testTheScreenIsToldWhyTheRequestDied(): void
    {
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $marked = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('markLatestCommand')->willReturnCallback(
            static function (string $imei, string $nativeType, array $fields) use (&$marked): void {
                $marked[] = [$nativeType, $fields];
            }
        );
        $bridge = $this->bridge(new RecordingHubMqttBridge(), $queue, $store);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement([
            'sdkType' => 51,
            'notWear' => true,
        ]));

        $failed = array_values(array_filter(
            $marked,
            static fn(array $m): bool => ($m[1]['status'] ?? '') === 'failed',
        ));

        self::assertCount(1, $failed);
        self::assertSame('measure.heartRate.start', $failed[0][0]);
        self::assertSame('not_worn', $failed[0][1]['error']);
    }

    /** O gateway confirma porque executou; sem valor nem razão da pulseira, confirmado diria «o gateway mandou» e não «a pulseira mediu». */
    public function testAMeasurementThatCameBackSilentIsReportedAsAFailure(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start', 'no_response'));

        self::assertSame([], $queue->operations(), 'o pedido não pode ficar em fila');

        $failures = self::failures($mqtt);
        self::assertCount(1, $failures);
        self::assertSame(['reason' => 'no_response'], $failures[0]['payload']['error']);
    }

    /** Uma confirmação sem o campo, de um gateway antigo, vale como sucesso: a falha tem de ser dita, não presumida. */
    public function testAConfirmationWithoutTheFieldIsStillASuccess(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('config:find_device');
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'command_result',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['dedupeKey' => 'config:find_device-key', 'operation' => 'config:find_device'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $queue->operations());
        self::assertSame([], self::failures($mqtt));
    }

    /** Um comando que o gateway não conseguiu entregar fica falhado, com a razão dele. */
    public function testACommandTheGatewayCouldNotDeliverFailsWithItsReason(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('config:find_device');
        $marked = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('markLatestCommand')->willReturnCallback(
            static function (string $imei, string $nativeType, array $fields) use (&$marked): void {
                $marked[] = $fields;
            }
        );
        $bridge = $this->bridge($mqtt, $queue, $store);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::commandResult('config:find_device', ['ok' => false, 'error' => 'disconnected']));

        self::assertSame([], $queue->operations());
        $last = end($marked);
        self::assertSame('failed', $last['status'] ?? null);
        self::assertSame('disconnected', $last['error'] ?? null);
        self::assertSame([], self::failures($mqtt), 'não é uma medição');
    }

    /** Vibrar e parar são a mesma operação: a falha de um não leva o outro. */
    public function testAFailedCommandTakesOnlyItsOwnKeyOutOfTheQueue(): void
    {
        $queue = new \Hub\Device\RedisPendingDownlinkQueue(new \Tests\Support\Doubles\InMemoryRedisClient());
        $start = $queue->enqueue(self::BRACELET, 'config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => true]], 300);
        $stop = $queue->enqueue(self::BRACELET, 'config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => false]], 300);
        $bridge = $this->bridge(new RecordingHubMqttBridge(), $queue);

        $bridge->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'command_result',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['dedupeKey' => $start->dedupeKey, 'operation' => 'config:find_device', 'ok' => false, 'error' => 'not_connected'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([$stop->dedupeKey], array_map(static fn ($d): string => $d->dedupeKey, $queue->pendingFor(self::BRACELET)));
    }

    /** Numa medição, a razão do gateway chega ao ecrã em vez de «sem leitura». */
    public function testAMeasurementTheGatewayCouldNotDeliverFailsWithItsReason(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::commandResult('measure.heartRate.start', ['ok' => false, 'error' => 'not_connected']));

        self::assertSame([], $queue->operations());
        self::assertSame(['reason' => 'not_connected'], self::failures($mqtt)[0]['payload']['error']);
    }

    /** Fora do pulso a pulseira responde com tramas a zero e o gateway confirma; decidir o que é leitura cabe ao hub. */
    public function testAMeasurementThatNeverProducedAReadingIsReportedAsAFailure(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        for ($i = 0; $i < 5; $i++) {
            $bridge->handleReceivedMessage(self::TOPIC, self::measurement(['sdkType' => 51, 'heartRate' => 0]));
        }
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start', null));

        self::assertSame([], $queue->operations());

        $failures = self::failures($mqtt);
        self::assertCount(1, $failures);
        self::assertSame(['reason' => 'no_reading'], $failures[0]['payload']['error']);
    }

    /** Com valor, a confirmação é uma confirmação. */
    public function testAMeasurementThatProducedAReadingIsConfirmed(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement(['sdkType' => 51, 'heartRate' => 0]));
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement(['sdkType' => 51, 'heartRate' => 87]));
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start', null));

        self::assertSame([], $queue->operations());
        self::assertSame([], self::failures($mqtt));
    }

    /**
     * E a leitura de um pedido não conta para o seguinte: cada um tem de dar o seu valor.
     */
    public function testTheReadingOfOneRequestDoesNotVouchForTheNext(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement(['sdkType' => 51, 'heartRate' => 87]));
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start', null));

        $queue->add('measure.heartRate.start');
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start', null));

        $failures = self::failures($mqtt);
        self::assertCount(1, $failures);
        self::assertSame(['reason' => 'no_reading'], $failures[0]['payload']['error']);
    }

    /** E o pedido seguinte volta a poder falhar por si. */
    public function testTheNextRequestCanFailOnItsOwn(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement(['sdkType' => 51, 'notWear' => true]));
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start', null));

        $queue->add('measure.heartRate.start');
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start', null));

        $reasons = array_map(
            static fn(array $e): mixed => $e['payload']['error']['reason'] ?? null,
            self::failures($mqtt),
        );

        self::assertSame(['not_worn', 'no_reading'], $reasons);
    }

    /** A onda chega em `ecg_wave` e não em tramas de medição, por isso não há nada a assentar quando a confirmação chega. */
    public function testAnEcgThatCaughtSignalIsNotReportedAsAFailure(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('measure.ecg.start');
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, json_encode([
            'source' => 'veepoo-node',
            'kind' => 'ecg_wave',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['samples' => [12, -4, 33, 128, -71], 'samplingHz' => 500],
        ], JSON_THROW_ON_ERROR));
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.ecg.start', null));

        self::assertSame([], $queue->operations());
        self::assertSame([], self::failures($mqtt));
    }

    /** A marca de um pedido expira, porque a confirmação que a consome pode nunca chegar. */
    public function testTheMarkOfADeadRequestExpires(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $now = 1000;
        $bridge = $this->bridge($mqtt, $queue, clock: static function () use (&$now): float {
            return (float)$now;
        });

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement(['sdkType' => 51, 'notWear' => true]));

        $now += 200;
        $bridge->dispatchQueued();

        $queue->add('measure.heartRate.start');
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start', null));

        $reasons = array_map(
            static fn(array $e): mixed => $e['payload']['error']['reason'] ?? null,
            self::failures($mqtt),
        );

        self::assertSame(['not_worn', 'no_reading'], $reasons);
    }

    /** Depois de a pulseira sair do pulso as tramas são medidas ao ar, por isso o pedido morto ganha à leitura tardia. */
    public function testADeadRequestOutranksTheReadingThatCameAfterIt(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement(['sdkType' => 51, 'notWear' => true]));
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement(['sdkType' => 51, 'heartRate' => 87]));
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start', null));

        self::assertSame([], array_filter(
            $mqtt->telemetry,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'heart_rate',
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function failures(RecordingHubMqttBridge $mqtt): array
    {
        return array_values(array_filter(
            $mqtt->events,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'device.measurement_failed',
        ));
    }

    private static function confirmation(string $operation, ?string $outcome): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'command_result',
            'device' => ['mac' => self::BRACELET],
            'payload' => array_filter([
                'dedupeKey' => $operation . '-key',
                'operation' => $operation,
                'outcome' => $outcome,
            ], static fn(mixed $v): bool => $v !== null),
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $fields */
    private static function commandResult(string $operation, array $fields): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'command_result',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['dedupeKey' => $operation . '-key', 'operation' => $operation] + $fields,
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    private static function measurement(array $payload): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'measurement',
            'device' => ['mac' => self::BRACELET],
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);
    }

    private static function session(): string
    {
        return json_encode([
            'source' => 'veepoo-node',
            'kind' => 'session',
            'device' => ['mac' => self::BRACELET],
            'payload' => ['authenticated' => true],
        ], JSON_THROW_ON_ERROR);
    }

    private function bridge(
        RecordingHubMqttBridge $mqtt,
        PendingDownlinkQueue $queue,
        ?DeviceStoreContract $store = null,
        ?callable $clock = null,
    ): VeepooBridge {
        return new VeepooBridge(
            new FakeMqttSubscriber(),
            IngressFixtures::whitelist([
                self::GATEWAY => IngressFixtures::device('Havicare', 'Veepoo Gateway', 'gateway'),
                self::BRACELET => IngressFixtures::device('Wonlex', 'MF91', 'bracelet'),
            ]),
            $mqtt,
            IngressFixtures::links(true),
            $queue,
            new ArrayObservationStateStore(),
            'havicare-hub/null/0/gw/+/raw',
            null,
            $store,
            clock: $clock,
        );
    }
}

/** A fila tira só o pedido indicado, para distinguir «tirou o pedido certo» de «tirou tudo». */
final class FakeQueue implements PendingDownlinkQueue
{
    /** @var list<PendingDownlink> */
    private array $items = [];

    public function add(string $operation): void
    {
        $this->items[] = new PendingDownlink('', $operation . '-key', $operation, null, 0, 0);
    }

    /** @return list<string> */
    public function operations(): array
    {
        return array_map(static fn(PendingDownlink $d): string => $d->bytes, $this->items);
    }

    public function enqueue(string $imei, string $bytes, ?array $command, int $ttlSeconds): PendingDownlink
    {
        return new PendingDownlink($imei, $bytes . '-key', $bytes, $command, 0, $ttlSeconds);
    }

    /** @return list<PendingDownlink> */
    public function pendingFor(string $imei): array
    {
        return array_map(
            static fn(PendingDownlink $d): PendingDownlink => new PendingDownlink(
                $imei,
                $d->dedupeKey,
                $d->bytes,
                $d->command,
                $d->queuedAt,
                $d->expiresAt,
            ),
            $this->items,
        );
    }

    public function remove(PendingDownlink $downlink): void
    {
        $this->items = array_values(array_filter(
            $this->items,
            static fn(PendingDownlink $d): bool => $d->dedupeKey !== $downlink->dedupeKey,
        ));
    }
}
