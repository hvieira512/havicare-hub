<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\State\DeviceStore;
use Hub\Device\CommercialModelResolver;
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

    /** @var list<callable():void> */
    private array $timers = [];

    private DeviceStore $store;

    private InMemoryRedisClient $redis;

    private FailingMaintenanceMqttBridge $mqtt;

    private RecordingMaintenanceHubServer $hubServer;

    protected function setUp(): void
    {
        $this->timers = [];
        $this->redis = new InMemoryRedisClient();
        $this->mqtt = new FailingMaintenanceMqttBridge();
        $this->store = new DeviceStore($this->redis);
        $this->store->registerDevice(self::IMEI, 'Vivistar', 'VIVISTAR-CARE');
        $this->hubServer = $this->recordingHubServer();

        MaintenanceScheduler::schedule($this->loop(), $this->services(), [
            'command_timeout_seconds' => self::COMMAND_TIMEOUT,
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

    /** Ao fim de três tentativas o comando falha, em vez de se repetir para sempre. */
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

    /** A repetição leva o contexto que o gateway precisa para executar a operação. */
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

    public function testIdleConnectionsAreSweptOnEveryTick(): void
    {
        $this->tick();

        self::assertSame(1, $this->hubServer->idleSweeps);
    }

    /** Um aparelho sem sessão própria, como um sensor atrás de um gateway, só se dá por desligado aqui. */
    public function testASilentDeviceWithoutItsOwnSessionIsAnnouncedDisconnected(): void
    {
        $this->store->deviceSeen('eec5000202f9', [
            'online' => '1', 'deviceType' => 'diaper_sensor', 'transport' => 'ble_gateway', 'protocol' => 'monit-mecs-pro-ble',
            'supplier' => 'MONIT', 'model' => 'MECS-PRO', 'licenseId' => 1001, 'company' => 'hitcare',
        ]);
        $this->rewindLastSeen('eec5000202f9', 2 * 600 + 30);

        $this->tick();

        self::assertSame(['device.disconnected'], array_column($this->mqtt->events, 'type'));
        self::assertSame('diaper_sensor', $this->mqtt->events[0]['deviceType']);
        self::assertSame(['device.disconnected'], array_column($this->store->recent('eec5000202f9', 'connections'), 'type'));
    }

    /** O hitCare lê o estado do gateway: `status` retido `offline` e o `device.disconnected`, na licença dele. */
    public function testASilentGatewayIsAnnouncedDisconnectedWhereHitCareReadsIt(): void
    {
        $this->store->deviceSeen('d48c49f7909c', [
            'online' => '1', 'deviceType' => 'gateway', 'transport' => 'mqtt', 'protocol' => 'moko-mkgw3',
            'supplier' => 'MOKO', 'model' => 'MKGW3', 'licenseId' => 1001, 'company' => 'hitcare',
        ]);
        $this->rewindLastSeen('d48c49f7909c', 2 * 30 + 30);

        $this->tick();

        self::assertSame(['device.disconnected'], array_column(array_column($this->mqtt->events, 'payload'), 'type'));
        self::assertSame('offline', $this->mqtt->statuses[0]['payload']['state']);
        self::assertTrue($this->mqtt->statuses[0]['retain']);
        self::assertSame(['gateway', 1001, 'hitcare'], [$this->mqtt->statuses[0]['deviceType'], $this->mqtt->statuses[0]['licenseId'], $this->mqtt->statuses[0]['company']]);
    }

    public function testASilentRadarIsAnnouncedDisconnected(): void
    {
        $this->store->deviceSeen('594B3CB31A87', ['online' => '1', 'deviceType' => 'radar', 'protocol' => 'qinglanst-radar']);
        $this->rewindLastSeen('594B3CB31A87', 2 * 60 + 30);

        $this->tick();

        self::assertSame(['offline'], array_column(array_column($this->mqtt->statuses, 'payload'), 'state'));
    }

    /** A cloud da Tuya diz quando o botão adormece: o silêncio entre despertares não o desliga. */
    public function testASilentTuyaButtonIsNotAnnouncedDisconnected(): void
    {
        $this->store->deviceSeen('bf1234567890abcdef', ['online' => '1', 'deviceType' => 'sos_button', 'protocol' => 'tuya-cloud']);
        $this->rewindLastSeen('bf1234567890abcdef', 86400);

        $this->tick();

        self::assertSame([], $this->mqtt->statuses);
        self::assertTrue($this->store->device('bf1234567890abcdef')['online']);
    }

    /** Uma publicação que falha não leva consigo o anúncio dos outros. */
    public function testOneFailedAnnouncementDoesNotStopTheNext(): void
    {
        $this->mqtt->failing = true;
        foreach (['d48c49f7909c', 'c5e390f30bce'] as $gateway) {
            $this->store->deviceSeen($gateway, ['online' => '1', 'deviceType' => 'gateway', 'protocol' => 'moko-mkgw3']);
            $this->rewindLastSeen($gateway, 2 * 30 + 30);
        }

        $this->tick();

        self::assertSame(2, $this->mqtt->attempts);
    }

    /** Uma ligação TCP aberta decide-a o servidor: a manutenção não a apaga nem a anuncia. */
    public function testASilentConnectedTcpDeviceIsLeftToTheServer(): void
    {
        $this->hubServer->connected = true;
        $this->store->deviceSeen(self::IMEI, ['online' => '1', 'deviceType' => 'watch', 'transport' => 'tcp', 'protocol' => 'vivistar-iw']);
        $this->rewindLastSeen(self::IMEI, 2 * 480 + 30);

        $this->tick();

        self::assertSame([], $this->mqtt->events);
        self::assertTrue($this->store->device(self::IMEI)['online']);
    }

    /** Depois de um reinício do hub, um relógio que não voltou não tem socket que o anuncie: anuncia-o a manutenção. */
    public function testATcpDeviceThatNeverReconnectedIsAnnouncedDisconnected(): void
    {
        $this->store->deviceSeen(self::IMEI, ['online' => '1', 'deviceType' => 'watch', 'transport' => 'tcp', 'protocol' => 'vivistar-iw']);
        $this->rewindLastSeen(self::IMEI, 2 * 480 + 30);

        $this->tick();

        self::assertSame(['device.disconnected'], array_column($this->mqtt->events, 'type'));
        self::assertSame(['offline'], array_column(array_column($this->mqtt->statuses, 'payload'), 'state'));
    }

    private function rewindLastSeen(string $imei, int $seconds): void
    {
        $this->redis->zadd('hub:dashboard:online-devices-by-last-seen', [$imei => time() - $seconds]);
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

    private function recordingHubServer(): RecordingMaintenanceHubServer
    {
        return new RecordingMaintenanceHubServer(IngressFixtures::whitelist(), new RecordingHubMqttBridge());
    }

    private function services(): HubServices
    {
        $reflection = new \ReflectionClass(HubServices::class);
        $services = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('deviceStore')->setValue($services, $this->store);
        $reflection->getProperty('hubServer')->setValue($services, $this->hubServer);
        $reflection->getProperty('mqttBridge')->setValue($services, $this->mqtt);
        $reflection->getProperty('commercialModelResolver')->setValue($services, new CommercialModelResolver());

        return $services;
    }
}

/** O servidor TCP sem sockets: guarda o que a manutenção lhe pede. */
final class RecordingMaintenanceHubServer extends DeviceHubServer
{
    /** @var list<array<string, mixed>> */
    public array $submitted = [];

    public int $idleSweeps = 0;

    public bool $connected = false;

    public function isConnected(string $imei): bool
    {
        return $this->connected;
    }

    public function submitDownlink(string $imei, string $bytes, ?array $context = null): string
    {
        $this->submitted[] = ['imei' => $imei, 'bytes' => $bytes, 'context' => $context];

        return 'sent';
    }

    public function expireIdleConnections(): void
    {
        $this->idleSweeps++;
    }
}

/** Conta as tentativas de publicar, e com `failing` recusa-as como um socket de saída cheio. */
final class FailingMaintenanceMqttBridge extends RecordingHubMqttBridge
{
    public bool $failing = false;

    public int $attempts = 0;

    public function publishStatus(string $imei, array $payload, bool $retain, string $deviceType, int $licenseId, string $company): void
    {
        $this->attempts++;
        if ($this->failing) {
            throw new \RuntimeException('publish refused');
        }
        parent::publishStatus($imei, $payload, $retain, $deviceType, $licenseId, $company);
    }
}
