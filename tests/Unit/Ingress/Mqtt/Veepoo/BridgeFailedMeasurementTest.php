<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt\Veepoo;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Device\PendingDownlink;
use Hub\Device\PendingDownlinkQueue;
use Hub\Ingress\Mqtt\Gateway\ArrayObservationStateStore;
use Hub\Ingress\Mqtt\Veepoo\Bridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\FakeMqttSubscriber;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/**
 * Uma medição que o aparelho não consegue fazer tem de fechar o pedido que a mandou fazer.
 *
 * O acontecimento `device.measurement_failed` diz porquê, mas dizer não é encerrar: o comando
 * ficava em fila, era reentregue de trinta em trinta segundos até expirar, e a pulseira
 * repetia uma medição que já se sabia que não ia dar valor. Uma pulseira fora do pulso gastou
 * assim três pontos de bateria numa manhã, a medir nada cinco vezes seguidas.
 */
final class BridgeFailedMeasurementTest extends TestCase
{
    private const GATEWAY = 'bef341903987';
    private const BRACELET = '9f69c4866e6c';
    private const TOPIC = 'havicare-hub/null/0/gw/bef341903987/raw';

    /** A pulseira diz que não está ao pulso, e o pedido de batimentos morre aí. */
    public function testAMeasurementTheBandCannotTakeStopsBeingRetried(): void
    {
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $bridge = $this->bridge(new RecordingHubMqttBridge(), $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::measurement([
            'sdkType' => 51,
            'notWear' => true,
        ]));

        self::assertSame([], $queue->operations());
    }

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

    /**
     * Um traçado todo a zeros é o mesmo caso pelo outro caminho: chega em `ecg_wave` e não
     * traz tipo do SDK nenhum, mas o pedido que o mandou fazer é sempre o mesmo.
     */
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
        $store = $this->createMock(DashboardStoreContract::class);
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

    /**
     * Uma medição que correu e não produziu trama nenhuma é uma falha, e não um sucesso.
     *
     * O gateway executa o comando, espera os quarenta e cinco segundos e confirma -- porque
     * de facto o executou. Se a pulseira não respondeu coisa nenhuma, nem valor nem razão, o
     * pedido ficava «confirmado» e vazio no ecrã, que é a ambiguidade que o relatório de
     * falhas existe para eliminar: confirmado passava a querer dizer «o gateway mandou» em
     * vez de «a pulseira mediu».
     */
    public function testAMeasurementThatCameBackSilentIsReportedAsAFailure(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start', 'no_response'));

        self::assertSame([], $queue->operations(), 'o pedido não pode ficar em fila');

        $failures = array_values(array_filter(
            $mqtt->events,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'device.measurement_failed',
        ));

        self::assertCount(1, $failures);
        self::assertSame(['reason' => 'no_response'], $failures[0]['payload']['error']);
    }

    /** Uma execução com resposta continua a ser um sucesso, e sai da fila calada. */
    public function testAMeasurementThatAnsweredIsNotReportedAsAFailure(): void
    {
        $mqtt = new RecordingHubMqttBridge();
        $queue = new FakeQueue();
        $queue->add('measure.heartRate.start');
        $bridge = $this->bridge($mqtt, $queue);

        $bridge->handleReceivedMessage(self::TOPIC, self::session());
        $bridge->handleReceivedMessage(self::TOPIC, self::confirmation('measure.heartRate.start', null));

        self::assertSame([], $queue->operations());
        self::assertSame([], array_filter(
            $mqtt->events,
            static fn(array $e): bool => ($e['payload']['type'] ?? null) === 'device.measurement_failed',
        ));
    }

    /**
     * Uma confirmação de um gateway antigo, sem o campo, continua a valer como sucesso: o
     * silêncio tem de ser dito, não presumido.
     */
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
        self::assertSame([], array_filter(
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
        ?DashboardStoreContract $store = null,
    ): Bridge {
        return new Bridge(
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
        );
    }
}

/**
 * Uma fila que tira mesmo o que lhe mandam tirar.
 *
 * O duplo das outras suites esvazia-se inteiro no `remove`, e com ele nenhum teste
 * conseguiria distinguir «tirou o pedido certo» de «tirou tudo».
 */
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
