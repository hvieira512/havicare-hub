<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Device\PendingDownlink;
use Hub\Device\RedisPendingDownlinkQueue;
use Hub\State\DeviceStoreContract;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\RecordingHubMqttBridge;
use Tests\Support\Doubles\VeepooFixtures;

/** Sai em `device.measurement_failed` e fecha o pedido, senão é reentregue e a pulseira repete uma medição sem valor. */
final class BridgeFailedMeasurementTest extends TestCase
{
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** E só esse: as outras medições em fila não sabem nada sobre esta. */
    public function testOnlyTheRequestThatFailedLeavesTheQueue(): void
    {
        $queue = self::queue('measure.heartRate.start', 'measure.oxygen.start');
        $bridge = VeepooFixtures::bridge(new RecordingHubMqttBridge(), $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::measurement([
            'sdkType' => 51,
            'notWear' => true,
        ]));

        self::assertSame(['measure.oxygen.start'], self::operations($queue));
    }

    /** Bateria fraca e sensor anómalo são razões do aparelho, e valem o mesmo. */
    public function testADeviceFailureAlsoClosesTheRequest(): void
    {
        $queue = self::queue('measure.bloodPressure.start');
        $bridge = VeepooFixtures::bridge(new RecordingHubMqttBridge(), $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::measurement([
            'sdkType' => 18,
            'deviceDetectionInfo' => 'atLowVoltage',
        ]));

        self::assertSame([], self::operations($queue));
    }

    /** Um traçado todo a zeros chega em `ecg_wave` sem tipo do SDK, mas o pedido que o originou é sempre o mesmo. */
    public function testAnEcgWithoutSignalClosesTheRequest(): void
    {
        $queue = self::queue('measure.ecg.start');
        $bridge = VeepooFixtures::bridge(new RecordingHubMqttBridge(), $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, VeepooFixtures::message('ecg_wave', ['samples' => [0, 0, 0, 0], 'samplingHz' => 500]));

        self::assertSame([], self::operations($queue));
    }

    /** Estar ocupada é passageiro: a medição a decorrer acaba e o pedido continua de pé. */
    public function testABusyBandKeepsTheRequestQueued(): void
    {
        $queue = self::queue('measure.heartRate.start');
        $bridge = VeepooFixtures::bridge(new RecordingHubMqttBridge(), $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::measurement([
            'sdkType' => 51,
            'deviceBusy' => true,
        ]));

        self::assertSame(['measure.heartRate.start'], self::operations($queue));
    }

    /** O ecrã tem de mostrar o pedido por falhado, e com a razão. */
    public function testTheScreenIsToldWhyTheRequestDied(): void
    {
        $queue = self::queue('measure.heartRate.start');
        $marked = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('markLatestCommand')->willReturnCallback(
            static function (string $imei, string $nativeType, array $fields) use (&$marked): void {
                $marked[] = [$nativeType, $fields];
            }
        );
        $bridge = VeepooFixtures::bridge(new RecordingHubMqttBridge(), $queue, $store);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::measurement([
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
        $queue = self::queue('measure.heartRate.start');
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::confirmation('measure.heartRate.start', 'no_response'));

        self::assertSame([], self::operations($queue), 'o pedido não pode ficar em fila');

        $failures = $mqtt->ofType('device.measurement_failed', 'events');
        self::assertCount(1, $failures);
        self::assertSame(['reason' => 'no_response'], $failures[0]['payload']['error']);
    }

    /** Uma confirmação sem o campo, de um gateway antigo, vale como sucesso: a falha tem de ser dita, não presumida. */
    public function testAConfirmationWithoutTheFieldIsStillASuccess(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue('config:find_device');
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, VeepooFixtures::message('command_result', ['dedupeKey' => self::dedupeKey('config:find_device'), 'operation' => 'config:find_device']));

        self::assertSame([], self::operations($queue));
        self::assertSame([], $mqtt->ofType('device.measurement_failed', 'events'));
    }

    /** Um comando que o gateway não conseguiu entregar fica falhado, com a razão dele. */
    public function testACommandTheGatewayCouldNotDeliverFailsWithItsReason(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue('config:find_device');
        $marked = [];
        $store = $this->createMock(DeviceStoreContract::class);
        $store->method('markLatestCommand')->willReturnCallback(
            static function (string $imei, string $nativeType, array $fields) use (&$marked): void {
                $marked[] = $fields;
            }
        );
        $bridge = VeepooFixtures::bridge($mqtt, $queue, $store);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::commandResult('config:find_device', ['ok' => false, 'error' => 'disconnected']));

        self::assertSame([], self::operations($queue));
        $last = end($marked);
        self::assertSame('failed', $last['status'] ?? null);
        self::assertSame('disconnected', $last['error'] ?? null);
        self::assertSame([], $mqtt->ofType('device.measurement_failed', 'events'), 'não é uma medição');
    }

    /** Vibrar e parar são a mesma operação: a falha de um não leva o outro. */
    public function testAFailedCommandTakesOnlyItsOwnKeyOutOfTheQueue(): void
    {
        $queue = new \Hub\Device\RedisPendingDownlinkQueue(new \Tests\Support\Doubles\InMemoryRedisClient());
        $start = $queue->enqueue(self::BRACELET, 'config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => true]], 300);
        $stop = $queue->enqueue(self::BRACELET, 'config:find_device', ['command' => 'config:find_device', 'payload' => ['enabled' => false]], 300);
        $bridge = VeepooFixtures::bridge(new RecordingHubMqttBridge(), $queue);

        IngressFixtures::relay($bridge, self::TOPIC, VeepooFixtures::message('command_result', ['dedupeKey' => $start->dedupeKey, 'operation' => 'config:find_device', 'ok' => false, 'error' => 'not_connected']));

        self::assertSame([$stop->dedupeKey], array_map(static fn ($d): string => $d->dedupeKey, $queue->pendingFor(self::BRACELET)));
    }

    /** Numa medição, a razão do gateway chega ao ecrã em vez de «sem leitura». */
    public function testAMeasurementTheGatewayCouldNotDeliverFailsWithItsReason(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue('measure.heartRate.start');
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::commandResult('measure.heartRate.start', ['ok' => false, 'error' => 'not_connected']));

        self::assertSame([], self::operations($queue));
        self::assertSame(['reason' => 'not_connected'], $mqtt->ofType('device.measurement_failed', 'events')[0]['payload']['error']);
    }

    /** Fora do pulso a pulseira responde com tramas a zero e o gateway confirma; decidir o que é leitura cabe ao hub. */
    public function testAMeasurementThatNeverProducedAReadingIsReportedAsAFailure(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue('measure.heartRate.start');
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        for ($i = 0; $i < 5; $i++) {
            IngressFixtures::relay($bridge, self::TOPIC, self::measurement(['sdkType' => 51, 'heartRate' => 0]));
        }
        IngressFixtures::relay($bridge, self::TOPIC, self::confirmation('measure.heartRate.start', null));

        self::assertSame([], self::operations($queue));

        $failures = $mqtt->ofType('device.measurement_failed', 'events');
        self::assertCount(1, $failures);
        self::assertSame(['reason' => 'no_reading'], $failures[0]['payload']['error']);
    }

    /** Com valor, a confirmação é uma confirmação. */
    public function testAMeasurementThatProducedAReadingIsConfirmed(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue('measure.heartRate.start');
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::measurement(['sdkType' => 51, 'heartRate' => 0]));
        IngressFixtures::relay($bridge, self::TOPIC, self::measurement(['sdkType' => 51, 'heartRate' => 87]));
        IngressFixtures::relay($bridge, self::TOPIC, self::confirmation('measure.heartRate.start', null));

        self::assertSame([], self::operations($queue));
        self::assertSame([], $mqtt->ofType('device.measurement_failed', 'events'));
    }

    /**
     * E a leitura de um pedido não conta para o seguinte: cada um tem de dar o seu valor.
     */
    public function testTheReadingOfOneRequestDoesNotVouchForTheNext(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue('measure.heartRate.start');
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::measurement(['sdkType' => 51, 'heartRate' => 87]));
        IngressFixtures::relay($bridge, self::TOPIC, self::confirmation('measure.heartRate.start', null));

        $queue->enqueue(self::BRACELET, 'measure.heartRate.start', null, 300);
        IngressFixtures::relay($bridge, self::TOPIC, self::confirmation('measure.heartRate.start', null));

        $failures = $mqtt->ofType('device.measurement_failed', 'events');
        self::assertCount(1, $failures);
        self::assertSame(['reason' => 'no_reading'], $failures[0]['payload']['error']);
    }

    /** E o pedido seguinte volta a poder falhar por si. */
    public function testTheNextRequestCanFailOnItsOwn(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue('measure.heartRate.start');
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::measurement(['sdkType' => 51, 'notWear' => true]));
        IngressFixtures::relay($bridge, self::TOPIC, self::confirmation('measure.heartRate.start', null));

        $queue->enqueue(self::BRACELET, 'measure.heartRate.start', null, 300);
        IngressFixtures::relay($bridge, self::TOPIC, self::confirmation('measure.heartRate.start', null));

        $reasons = array_map(
            static fn(array $e): mixed => $e['payload']['error']['reason'] ?? null,
            $mqtt->ofType('device.measurement_failed', 'events'),
        );

        self::assertSame(['not_worn', 'no_reading'], $reasons);
    }

    /** A onda chega em `ecg_wave` e não em tramas de medição, por isso não há nada a assentar quando a confirmação chega. */
    public function testAnEcgThatCaughtSignalIsNotReportedAsAFailure(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue('measure.ecg.start');
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, VeepooFixtures::message('ecg_wave', ['samples' => [12, -4, 33, 128, -71], 'samplingHz' => 500]));
        IngressFixtures::relay($bridge, self::TOPIC, self::confirmation('measure.ecg.start', null));

        self::assertSame([], self::operations($queue));
        self::assertSame([], $mqtt->ofType('device.measurement_failed', 'events'));
    }

    /** A marca de um pedido expira, porque a confirmação que a consome pode nunca chegar. */
    public function testTheMarkOfADeadRequestExpires(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue('measure.heartRate.start');
        $now = 1000;
        $bridge = VeepooFixtures::bridge($mqtt, $queue, clock: static function () use (&$now): float {
            return (float)$now;
        });

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::measurement(['sdkType' => 51, 'notWear' => true]));

        $now += 200;
        $bridge->dispatchQueued();

        $queue->enqueue(self::BRACELET, 'measure.heartRate.start', null, 300);
        IngressFixtures::relay($bridge, self::TOPIC, self::confirmation('measure.heartRate.start', null));

        $reasons = array_map(
            static fn(array $e): mixed => $e['payload']['error']['reason'] ?? null,
            $mqtt->ofType('device.measurement_failed', 'events'),
        );

        self::assertSame(['not_worn', 'no_reading'], $reasons);
    }

    /** Depois de a pulseira sair do pulso as tramas são medidas ao ar, por isso o pedido morto ganha à leitura tardia. */
    public function testADeadRequestOutranksTheReadingThatCameAfterIt(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = self::queue('measure.heartRate.start');
        $bridge = VeepooFixtures::bridge($mqtt, $queue);

        IngressFixtures::relay($bridge, self::TOPIC, self::session());
        IngressFixtures::relay($bridge, self::TOPIC, self::measurement(['sdkType' => 51, 'notWear' => true]));
        IngressFixtures::relay($bridge, self::TOPIC, self::measurement(['sdkType' => 51, 'heartRate' => 87]));
        IngressFixtures::relay($bridge, self::TOPIC, self::confirmation('measure.heartRate.start', null));

        self::assertSame([], $mqtt->ofType('heart_rate'));
    }


    private static function confirmation(string $operation, ?string $outcome): string
    {
        return VeepooFixtures::message('command_result', array_filter([
            'dedupeKey' => self::dedupeKey($operation),
            'operation' => $operation,
            'outcome' => $outcome,
        ], static fn(mixed $v): bool => $v !== null));
    }

    /** @param array<string, mixed> $fields */
    private static function commandResult(string $operation, array $fields): string
    {
        return VeepooFixtures::message('command_result', ['dedupeKey' => self::dedupeKey($operation), 'operation' => $operation] + $fields);
    }

    /** @param array<string, mixed> $payload */
    private static function measurement(array $payload): string
    {
        return VeepooFixtures::message('measurement', $payload);
    }

    private static function session(): string
    {
        return VeepooFixtures::message('session', ['authenticated' => true]);
    }

    private static function queue(string ...$operations): RedisPendingDownlinkQueue
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        foreach ($operations as $operation) {
            $queue->enqueue(self::BRACELET, $operation, null, 300);
        }

        return $queue;
    }

    /** @return list<string> */
    private static function operations(RedisPendingDownlinkQueue $queue): array
    {
        return array_map(static fn(PendingDownlink $d): string => $d->bytes, array_values($queue->pendingFor(self::BRACELET)));
    }

    private static function dedupeKey(string $operation): string
    {
        return 'raw:' . hash('sha256', $operation);
    }
}
