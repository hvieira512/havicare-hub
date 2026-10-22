<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Http\Qinglanst;

use Hub\Device\PendingDownlink;
use Hub\Device\PendingDownlinkQueue;
use Hub\Ingress\Http\Qinglanst\QinglanstApiClient;
use Hub\Ingress\Http\Qinglanst\QinglanstCloudCommands;
use Hub\Ingress\Http\Qinglanst\QinglanstCloudDownlinks;
use Hub\Device\RedisPendingDownlinkQueue;
use Tests\Support\Doubles\InMemoryRedisClient;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/** O código da resposta da cloud decide: 200 aplicado, 777 radar desligado e fica à espera, 778 modo recusado sem repetir. */
final class QinglanstCloudDownlinksTest extends TestCase
{
    private const IMEI = 'AD8A613B0493';

    /** @var list<array{id: string, fields: array<string, mixed>}> */
    private array $marks = [];

    /** @var list<string> */
    private array $reads = [];

    public function testASettingIsWrittenAndConfirmedByTheCloudAnswer(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue(self::IMEI, 'working_mode', ['id' => 'op-1', 'command' => 'working_mode', 'payload' => ['mode' => 'sleep_monitoring']], 300);
        $client = new ScriptedCloudClient(prop: 200, writes: [200]);

        $this->await($this->downlinks($queue, $client)->dispatchPending(self::IMEI, now: true));

        self::assertSame([['/thirdparty/v2/prop', ['uid' => self::IMEI, 'scene' => '4']]], $client->posts);
        self::assertSame([], $queue->pendingFor(self::IMEI));
        self::assertSame('acked', $this->lastMark('op-1')['status']);
        self::assertSame([self::IMEI], $this->reads, 'depois de escrever, o hub volta a ler o radar');
    }

    public function testAnOfflineRadarKeepsTheChangeQueued(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue(self::IMEI, 'fall_alarm', ['id' => 'op-2', 'command' => 'fall_alarm', 'payload' => ['afterSeconds' => 60]], 300);
        $client = new ScriptedCloudClient(prop: 777, writes: []);
        $downlinks = $this->downlinks($queue, $client);

        $this->await($downlinks->dispatchPending(self::IMEI, now: true));
        $this->await($downlinks->dispatchPending(self::IMEI, now: true));

        self::assertCount(1, $queue->pendingFor(self::IMEI));
        self::assertSame(['status' => 'queued', 'error' => 'device_offline'], array_intersect_key($this->lastMark('op-2'), ['status' => 1, 'error' => 1]));
        self::assertSame(1, $client->propReads, 'enquanto espera, não volta a perguntar a cada mensagem');
    }

    public function testAModeTheModelRefusesFailsWithoutRetrying(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue(self::IMEI, 'working_mode', ['id' => 'op-3', 'command' => 'working_mode', 'payload' => ['mode' => 'sleep_monitoring']], 300);

        $this->await($this->downlinks($queue, new ScriptedCloudClient(prop: 200, writes: [778]))->dispatchPending(self::IMEI, now: true));

        self::assertSame([], $queue->pendingFor(self::IMEI));
        self::assertSame(['status' => 'failed', 'error' => 'mode_not_supported'], array_intersect_key($this->lastMark('op-3'), ['status' => 1, 'error' => 1]));
    }

    /** Ler o radar não escreve nada: é a leitura que fica guardada. */
    public function testReadingTheRadarOnlyReadsIt(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue(self::IMEI, 'read_device_state', ['id' => 'op-4', 'command' => 'read_device_state', 'payload' => []], 300);
        $client = new ScriptedCloudClient(prop: 200, writes: []);

        $this->await($this->downlinks($queue, $client)->dispatchPending(self::IMEI, now: true));

        self::assertSame([], $client->posts);
        self::assertSame([self::IMEI], $this->reads);
        self::assertSame('acked', $this->lastMark('op-4')['status']);
    }

    /** Uma leitura sem resposta (a cloud a passar o tempo limite) diz isso, com o motivo. */
    public function testAReadTheCloudNeverAnsweredSaysSo(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue(self::IMEI, 'read_device_state', ['id' => 'op-9', 'command' => 'read_device_state', 'payload' => []], 300);
        $downlinks = new QinglanstCloudDownlinks(
            $queue,
            new ScriptedCloudClient(prop: 200, writes: []),
            new QinglanstCloudCommands(),
            static fn (string $imei): ?array => ['uid' => $imei, 'credentials' => []],
            static fn (string $imei): PromiseInterface => resolve(['codes' => [], 'error' => 'Qinglanst login failed: timed out']),
            fn (string $imei, string $id, array $fields) => $this->marks[] = ['id' => $id, 'fields' => $fields],
        );

        $this->await($downlinks->dispatchPending(self::IMEI, now: true));

        self::assertSame(
            ['status' => 'failed', 'error' => 'cloud_unreachable', 'errorMessage' => 'Qinglanst login failed: timed out'],
            array_intersect_key($this->lastMark('op-9'), ['status' => 1, 'error' => 1, 'errorMessage' => 1]),
        );
    }

    public function testARadarWhoseLicenceHasNoCredentialsFails(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue(self::IMEI, 'fall_alarm', ['id' => 'op-5', 'command' => 'fall_alarm', 'payload' => ['afterSeconds' => 60]], 300);
        $downlinks = new QinglanstCloudDownlinks(
            $queue,
            new ScriptedCloudClient(prop: 200, writes: []),
            new QinglanstCloudCommands(),
            static fn (string $imei): ?array => ['uid' => $imei, 'credentials' => null],
            fn (string $imei): PromiseInterface => resolve(['codes' => []]),
            fn (string $imei, string $id, array $fields) => $this->marks[] = ['id' => $id, 'fields' => $fields],
        );

        $this->await($downlinks->dispatchPending(self::IMEI, now: true));

        self::assertSame('license_has_no_radar_credentials', $this->lastMark('op-5')['error']);
    }

    /** O que entra enquanto o radar está ocupado sai logo a seguir, sem esperar pela próxima mensagem dele. */
    public function testWhatIsQueuedWhileBusyGoesRightAfter(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $client = new DeferredCloudClient();
        $downlinks = $this->downlinks($queue, $client);
        $queue->enqueue(self::IMEI, 'fall_alarm', ['id' => 'op-7', 'command' => 'fall_alarm', 'payload' => ['afterSeconds' => 60]], 300);
        $downlinks->dispatchPending(self::IMEI, now: true);

        $queue->enqueue(self::IMEI, 'working_mode', ['id' => 'op-8', 'command' => 'working_mode', 'payload' => ['mode' => 'fall_detection']], 300);
        $downlinks->dispatchPending(self::IMEI, now: true);
        $client->answerAll();

        self::assertSame('acked', $this->lastMark('op-8')['status'] ?? null);
    }

    /** Um aparelho que não é radar fica na fila para quem o entrega pelo socket. */
    public function testWhatIsQueuedForAnotherDeviceIsLeftAlone(): void
    {
        $queue = new RedisPendingDownlinkQueue(new InMemoryRedisClient());
        $queue->enqueue('865028000000308', 'IWBP84', ['id' => 'op-6', 'command' => 'BP84', 'payload' => []], 300);
        $downlinks = new QinglanstCloudDownlinks(
            $queue,
            new ScriptedCloudClient(prop: 200, writes: []),
            new QinglanstCloudCommands(),
            static fn (string $imei): ?array => null,
            fn (string $imei): PromiseInterface => resolve(['codes' => []]),
            fn (string $imei, string $id, array $fields) => $this->marks[] = ['id' => $id, 'fields' => $fields],
        );

        $this->await($downlinks->dispatchPending('865028000000308', now: true));

        self::assertCount(1, $queue->pendingFor('865028000000308'));
        self::assertSame([], $this->marks);
    }

    /** As mensagens do radar chegam várias por segundo: a fila só se lê de poucos em poucos segundos. */
    public function testTheMessagesOfARadarReadTheQueueOnlyEveryFewSeconds(): void
    {
        $queue = new CountingRadarQueue();
        $downlinks = $this->downlinks($queue, new ScriptedCloudClient(prop: 200, writes: []));

        foreach (range(1, 10) as $message) {
            $this->await($downlinks->dispatchPending(self::IMEI));
        }
        $this->await($downlinks->dispatchPending(self::IMEI, now: true));

        self::assertSame(2, $queue->reads);
    }

    private function downlinks(PendingDownlinkQueue $queue, QinglanstApiClient $client): QinglanstCloudDownlinks
    {
        return new QinglanstCloudDownlinks(
            $queue,
            $client,
            new QinglanstCloudCommands(),
            static fn (string $imei): ?array => ['uid' => $imei, 'credentials' => ['base_url' => 'https://qinglanst.com/prod-api', 'username' => 'u', 'password' => 'p', 'app_id' => 'a', 'app_secret' => 's']],
            function (string $imei): PromiseInterface {
                $this->reads[] = $imei;

                return resolve(['codes' => ['200' => 1]]);
            },
            fn (string $imei, string $id, array $fields) => $this->marks[] = ['id' => $id, 'fields' => $fields],
        );
    }

    /** @return array<string, mixed> */
    private function lastMark(string $id): array
    {
        $fields = [];
        foreach ($this->marks as $mark) {
            if ($mark['id'] === $id) {
                $fields = $mark['fields'];
            }
        }

        return $fields;
    }

    /** @param PromiseInterface<mixed> $promise */
    private function await(PromiseInterface $promise): void
    {
        $failure = null;
        $promise->then(null, static function (mixed $error) use (&$failure): void {
            $failure = $error;
        });
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
    }
}

/** A cloud com as respostas escritas: um código para o `deviceProp` e um por escrita, por ordem. */
final class ScriptedCloudClient extends QinglanstApiClient
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $posts = [];
    public int $propReads = 0;

    /** @param list<int> $writes */
    public function __construct(private int $prop, private array $writes)
    {
    }

    public function login(array $credentials): PromiseInterface
    {
        return resolve(['access_token' => 't', 'refresh_token' => '', 'token_type' => 'bearer', 'expires_in' => 3600]);
    }

    public function deviceProp(array $credentials, array $token, string $uid): PromiseInterface
    {
        $this->propReads++;

        return resolve(['code' => $this->prop, 'data' => $this->prop === 200 ? [
            'radar_func_ctrl' => '15',
            'radar_install_style' => '0',
            'postureParams' => '2,3,3',
            'heart_breath_param' => '[24, 90, 8, 50, 0, 5, 1]',
        ] : null]);
    }

    public function post(array $credentials, array $token, string $path, array $body): PromiseInterface
    {
        $this->posts[] = [$path, $body];

        return resolve(['code' => array_shift($this->writes) ?? 200, 'msg' => '']);
    }
}

/** A cloud que só responde quando o teste manda, para haver um radar ocupado. */
final class DeferredCloudClient extends QinglanstApiClient
{
    /** @var list<\React\Promise\Deferred<array<string, mixed>>> */
    private array $pending = [];

    public function __construct()
    {
    }

    public function login(array $credentials): PromiseInterface
    {
        return resolve(['access_token' => 't', 'refresh_token' => '', 'token_type' => 'bearer', 'expires_in' => 3600]);
    }

    public function deviceProp(array $credentials, array $token, string $uid): PromiseInterface
    {
        return resolve(['code' => 200, 'data' => ['radar_func_ctrl' => '15', 'postureParams' => '2,3,3']]);
    }

    public function post(array $credentials, array $token, string $path, array $body): PromiseInterface
    {
        $deferred = new \React\Promise\Deferred();
        $this->pending[] = $deferred;

        return $deferred->promise();
    }

    public function answerAll(): void
    {
        while ($this->pending !== []) {
            array_shift($this->pending)->resolve(['code' => 200, 'msg' => '']);
        }
    }
}

final class CountingRadarQueue implements PendingDownlinkQueue
{
    public int $reads = 0;

    public function enqueue(string $imei, string $bytes, ?array $command, int $ttlSeconds): PendingDownlink
    {
        throw new \LogicException('not used');
    }

    public function pendingFor(string $imei): array
    {
        $this->reads++;

        return [];
    }

    public function remove(PendingDownlink $downlink): void
    {
    }
}
