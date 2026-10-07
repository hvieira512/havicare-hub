<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\State\DeviceStore;
use Hub\Device\DeviceHubServer;
use Hub\Runtime\HubServices;
use Hub\Runtime\MaintenanceScheduler;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\RecordingHubMqttBridge;

/** A manutenção periódica: repetir o que ficou por entregar e largar o que se calou. */
final class MaintenanceSchedulerTest extends TestCase
{
    private const IMEI = '861265061009822';
    private const COMMAND_TIMEOUT = 3600;
    private const IDLE_TIMEOUT = 300;

    /** @var list<callable():void> */
    private array $timers = [];

    private DeviceStore $store;

    private InMemoryRedisClient $redis;

    private RecordingHubMqttBridge $mqtt;

    /** @var DeviceHubServer&object{submitted: list<array<string, mixed>>, idleSeconds: list<int>} */
    private DeviceHubServer $hubServer;

    protected function setUp(): void
    {
        $this->timers = [];
        $this->redis = new InMemoryRedisClient();
        $this->mqtt = new RecordingHubMqttBridge();
        $this->store = new DeviceStore($this->redis, prefix: 'test:dashboard');
        $this->store->registerDevice(self::IMEI, 'Vivistar', 'VIVISTAR-CARE');
        $this->hubServer = $this->recordingHubServer();

        MaintenanceScheduler::schedule($this->loop(), $this->services(), [
            'command_timeout_seconds' => self::COMMAND_TIMEOUT,
            'device_idle_timeout_seconds' => self::IDLE_TIMEOUT,
        ]);
    }

    /** Um comando por entregar volta a ser entregue na primeira passagem que o encontre. */
    public function testAWaitingCommandIsRetried(): void
    {
        $this->recordWaitingCommand();

        $this->tick();

        self::assertCount(1, $this->hubServer->submitted);
        self::assertSame(self::IMEI, $this->hubServer->submitted[0]['imei']);
        self::assertSame('IWBP76,1', $this->hubServer->submitted[0]['bytes']);
    }

    /** Entre duas tentativas há um minuto de espera, e a manutenção corre de dez em dez segundos. */
    public function testARetriedCommandIsNotRetriedAgainBeforeTheRetryInterval(): void
    {
        $this->recordWaitingCommand();

        $this->tick();
        $this->tick();
        $this->tick();

        self::assertCount(1, $this->hubServer->submitted);

        $scheduled = strtotime((string)$this->command()['nextRetryAt']);
        self::assertEqualsWithDelta(time() + 60, $scheduled, 2.0, 'a próxima tentativa é daqui a um minuto');
    }

    /** Passada a espera, a passagem seguinte volta a tentar. */
    public function testOnceTheRetryIntervalHasPassedTheCommandIsRetriedAgain(): void
    {
        $this->recordWaitingCommand();

        $this->tick();
        $this->rewindNextRetry();
        $this->tick();

        self::assertCount(2, $this->hubServer->submitted);
    }

    /**
     * Ao fim de três tentativas o comando falha em vez de ser repetido para sempre: um
     * aparelho que nunca responde não pode ocupar a fila até ao fim do tempo de resposta.
     */
    public function testAfterTheLastAttemptTheCommandIsNoLongerRetried(): void
    {
        $this->recordWaitingCommand();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->rewindNextRetry();
            $this->tick();
        }

        // O registo entra com a tentativa original já contada, por isso restam duas.
        self::assertCount(2, $this->hubServer->submitted, 'três tentativas ao todo e não mais');
        self::assertSame('failed', $this->command()['status']);
        self::assertSame('retry_exhausted', $this->command()['error']);
    }

    /**
     * O contexto volta com a repetição: nos protocolos de gateway os bytes em fila são só o
     * nome da operação, e sem o valor ao lado o gateway executa-a com os campos por preencher.
     */
    public function testTheRetryCarriesTheContextTheGatewayNeedsToExecuteIt(): void
    {
        $this->store->recordCommand(self::IMEI, 'cmd-1', [
            'status' => 'waiting',
            'retryable' => true,
            'protocol' => 'veepoo-ble',
            'nativeType' => 'config:heart_rate_continuous',
            'operationId' => 'op-77',
            'payload' => ['enabled' => false],
            'bytes' => 'config:heart_rate_continuous',
            'attempts' => 1,
            'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);

        $this->tick();

        $context = $this->hubServer->submitted[0]['context'];
        self::assertIsArray($context);
        self::assertSame('config:heart_rate_continuous', $context['command']);
        self::assertSame('cmd-1', $context['id']);
        self::assertSame('op-77', $context['operationId']);
        self::assertSame(['enabled' => false], $context['payload']);
    }

    /** Um comando sem resposta ao fim do tempo de espera falha em vez de ficar eternamente pendente. */
    public function testACommandThatRanOutOfTimeIsExpired(): void
    {
        $this->store->recordCommand(self::IMEI, 'cmd-old', [
            'status' => 'waiting',
            'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - self::COMMAND_TIMEOUT - 60),
            'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - self::COMMAND_TIMEOUT - 60),
        ]);

        $this->tick();

        self::assertSame('failed', $this->command('cmd-old')['status']);
    }

    /** As ligações paradas largam-se pelo tempo do aparelho, e não pelo do comando. */
    public function testIdleConnectionsAreExpiredWithTheDeviceTimeout(): void
    {
        $this->tick();

        self::assertSame([self::IDLE_TIMEOUT], $this->hubServer->idleSeconds);
    }

    /** Um aparelho sem sessão própria, como um sensor atrás de um gateway, só se dá por desligado aqui. */
    public function testASilentDeviceWithoutItsOwnSessionIsAnnouncedDisconnected(): void
    {
        $this->store->deviceSeen('eec5000202f9', [
            'online' => '1', 'deviceType' => 'diaper_sensor', 'transport' => 'ble_gateway',
            'supplier' => 'MONIT', 'model' => 'MECS-PRO', 'licenseId' => 1001, 'company' => 'hitcare',
        ]);
        $this->rewindLastSeen('eec5000202f9');

        $this->tick();

        self::assertSame(['device.disconnected'], array_column($this->mqtt->events, 'type'));
        self::assertSame('diaper_sensor', $this->mqtt->events[0]['deviceType']);
        self::assertSame(['device.disconnected'], array_column($this->store->recent('eec5000202f9', 'connections'), 'type'));
    }

    /** As ligações TCP anuncia-as o servidor ao fechar o socket; aqui só se apagam. */
    public function testASilentTcpDeviceExpiresWithoutAnAnnouncement(): void
    {
        $this->store->deviceSeen(self::IMEI, ['online' => '1', 'deviceType' => 'watch', 'transport' => 'tcp']);
        $this->rewindLastSeen(self::IMEI);

        $this->tick();

        self::assertSame([], $this->mqtt->events);
        self::assertFalse($this->store->device(self::IMEI)['online']);
    }

    private function rewindLastSeen(string $imei): void
    {
        $this->redis->zadd('test:dashboard:online-devices-by-last-seen', [$imei => time() - self::IDLE_TIMEOUT - 1]);
    }

    /** Corre uma passagem completa de manutenção. */
    private function tick(): void
    {
        foreach ($this->timers as $timer) {
            $timer();
        }
    }

    /** Põe a próxima tentativa no passado, que é onde o relógio a teria deixado. */
    private function rewindNextRetry(): void
    {
        $command = $this->command();
        if (($command['status'] ?? '') !== 'waiting') {
            return;
        }
        $this->store->recordCommand(self::IMEI, 'cmd-1', array_merge($command, [
            'nextRetryAt' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 1),
        ]));
    }

    private function recordWaitingCommand(): void
    {
        // Sem `maxAttempts` nem `retryDelaySeconds`: são estes os comandos cujo ritmo e
        // tecto de tentativas vêm do agendador.
        $this->store->recordCommand(self::IMEI, 'cmd-1', [
            'status' => 'waiting',
            'retryable' => true,
            'protocol' => 'vivistar-iw',
            'bytes' => 'IWBP76,1',
            'attempts' => 1,
            'sentAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);
    }

    /** @return array<string, mixed> */
    private function command(string $id = 'cmd-1'): array
    {
        foreach ($this->store->commands(self::IMEI) as $command) {
            if (($command['id'] ?? '') === $id) {
                return $command;
            }
        }

        self::fail("o comando {$id} desapareceu do histórico");
    }

    private function loop(): LoopInterface
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->method('addPeriodicTimer')->willReturnCallback(
            function (float $interval, callable $callback): TimerInterface {
                $this->timers[] = $callback;

                return $this->createMock(TimerInterface::class);
            }
        );

        return $loop;
    }

    /** @return DeviceHubServer&object{submitted: list<array<string, mixed>>, idleSeconds: list<int>} */
    private function recordingHubServer(): DeviceHubServer
    {
        return new class (IngressFixtures::whitelist(), new RecordingHubMqttBridge()) extends DeviceHubServer {
            /** @var list<array<string, mixed>> */
            public array $submitted = [];

            /** @var list<int> */
            public array $idleSeconds = [];

            public function submitDownlink(string $imei, string $bytes, ?array $context = null): string
            {
                $this->submitted[] = ['imei' => $imei, 'bytes' => $bytes, 'context' => $context];

                return 'sent';
            }

            public function expireIdleConnections(int $idleSeconds): void
            {
                $this->idleSeconds[] = $idleSeconds;
            }
        };
    }

    /** O agendador só toca em dois serviços, e construir os outros exigiria MySQL e um broker. */
    private function services(): HubServices
    {
        $reflection = new \ReflectionClass(HubServices::class);
        $services = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('deviceStore')->setValue($services, $this->store);
        $reflection->getProperty('hubServer')->setValue($services, $this->hubServer);
        $reflection->getProperty('mqttBridge')->setValue($services, $this->mqtt);

        return $services;
    }
}
