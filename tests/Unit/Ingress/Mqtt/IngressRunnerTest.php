<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Mqtt;

use Hub\Ingress\Mqtt\DispatchesQueued;
use Hub\Ingress\Mqtt\IngressRunner;
use Hub\Ingress\Mqtt\MqttIngress;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

final class IngressRunnerTest extends TestCase
{
    public function testIgnoresNullIngresses(): void
    {
        $runner = new IngressRunner(new StreamSelectLoop());
        $runner->add('NCS ingress', null);
        $runner->add('MQTT downlink', new StartableIngress());

        self::assertSame(['MQTT downlink'], $runner->names());
    }

    public function testStartsEveryRegisteredIngress(): void
    {
        $downlink = new StartableIngress();
        $ncs = new StartableIngress();

        $runner = new IngressRunner(new StreamSelectLoop());
        $runner->add('MQTT downlink', $downlink);
        $runner->add('NCS ingress', $ncs);
        $runner->start();

        self::assertSame(1, $downlink->starts);
        self::assertSame(1, $ncs->starts);
    }

    public function testStartFailureIsLabelledWithTheIngressName(): void
    {
        $runner = new IngressRunner(new StreamSelectLoop());
        $runner->add('NCS ingress', new StartableIngress(new \RuntimeException('broker unreachable')));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NCS ingress subscription failed: broker unreachable');

        $runner->start();
    }

    public function testStartFailurePreservesTheOriginalException(): void
    {
        $original = new \RuntimeException('broker unreachable');
        $runner = new IngressRunner(new StreamSelectLoop());
        $runner->add('NCS ingress', new StartableIngress($original));

        try {
            $runner->start();
            self::fail('Expected the runner to rethrow');
        } catch (\RuntimeException $e) {
            self::assertSame($original, $e->getPrevious());
        }
    }

    public function testScheduledTickDrivesTheIngress(): void
    {
        $loop = new StreamSelectLoop();
        $ingress = new StartableIngress();

        $runner = new IngressRunner($loop);
        $runner->add('MQTT downlink', $ingress);
        $runner->scheduleTicks(0.01, 0.001);

        $loop->addTimer(0.05, static fn () => $loop->stop());
        $loop->run();

        self::assertGreaterThan(0, $ingress->ticks);
        self::assertSame(0.001, $ingress->lastTimeout);
    }

    /**
     * Uma ingestão com fila para entregar é drenada por um temporizador do runner.
     *
     * Antes o `bin/server-hub.php` guardava a bridge Veepoo numa variável só para lhe
     * pendurar este temporizador, e o ficheiro de arranque passava a ter de saber que aquele
     * fornecedor -- e só aquele -- tinha uma fila. Quem sabe conduzir ingestões é o runner.
     */
    public function testAnIngressWithAQueueIsDrainedByTheRunner(): void
    {
        $loop = new StreamSelectLoop();
        $ingress = new QueueDrainingIngress();

        $runner = new IngressRunner($loop);
        $runner->add('Veepoo bracelet ingress', $ingress);
        $runner->scheduleTicks(0.01, 0.001, 0.01);

        $loop->addTimer(0.05, static fn () => $loop->stop());
        $loop->run();

        self::assertGreaterThan(0, $ingress->dispatches, 'a fila tem de ser drenada sem o entrypoint pedir');
    }

    /** Uma ingestão sem fila não ganha temporizador nenhum por causa disto. */
    public function testAnIngressWithoutAQueueIsLeftAlone(): void
    {
        $loop = new StreamSelectLoop();
        $ingress = new StartableIngress();

        $runner = new IngressRunner($loop);
        $runner->add('NCS ingress', $ingress);
        $runner->scheduleTicks(0.01, 0.001, 0.01);

        $loop->addTimer(0.05, static fn () => $loop->stop());
        $loop->run();

        self::assertGreaterThan(0, $ingress->ticks);
    }

    /** Uma entrega que estoira não pode derrubar o loop, como já acontece com o tick. */
    public function testADispatchFailureIsSwallowedSoTheLoopSurvives(): void
    {
        $loop = new StreamSelectLoop();
        $ingress = new QueueDrainingIngress(new \RuntimeException('gateway unreachable'));

        $runner = new IngressRunner($loop);
        $runner->add('Veepoo bracelet ingress', $ingress);
        $runner->scheduleTicks(0.01, 0.001, 0.01);

        $loop->addTimer(0.05, static fn () => $loop->stop());
        $loop->run();

        self::assertGreaterThan(0, $ingress->dispatches);
    }

    public function testTickFailureIsSwallowedSoTheLoopSurvives(): void
    {
        $loop = new StreamSelectLoop();
        $ingress = new StartableIngress(null, new \RuntimeException('connection lost'));

        $runner = new IngressRunner($loop);
        $runner->add('MQTT downlink', $ingress);
        $runner->scheduleTicks(0.01, 0.001);

        $loop->addTimer(0.05, static fn () => $loop->stop());
        $loop->run();

        self::assertGreaterThan(0, $ingress->ticks);
    }
}

/** Uma ingestão que tem o que entregar entre mensagens -- o caso da bridge Veepoo. */
final class QueueDrainingIngress implements MqttIngress, DispatchesQueued
{
    public int $dispatches = 0;

    public function __construct(private readonly ?\Throwable $dispatchError = null)
    {
    }

    public function dispatchQueued(): void
    {
        $this->dispatches++;
        if ($this->dispatchError !== null) {
            throw $this->dispatchError;
        }
    }

    public function start(): void
    {
    }

    public function tick(float $timeout = 0.01): void
    {
    }

    public function handleReceivedMessage(string $topic, string $payload): void
    {
    }
}

final class StartableIngress implements MqttIngress
{
    public int $starts = 0;
    public int $ticks = 0;
    public ?float $lastTimeout = null;

    public function __construct(
        private readonly ?\Throwable $startError = null,
        private readonly ?\Throwable $tickError = null,
    ) {
    }

    public function start(): void
    {
        $this->starts++;
        if ($this->startError !== null) {
            throw $this->startError;
        }
    }

    public function tick(float $timeout = 0.01): void
    {
        $this->ticks++;
        $this->lastTimeout = $timeout;
        if ($this->tickError !== null) {
            throw $this->tickError;
        }
    }

    public function handleReceivedMessage(string $topic, string $payload): void
    {
    }
}
