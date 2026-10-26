<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\State\DeviceStore;
use Predis\Client as RedisClient;
use Tests\Support\MysqlDashboardTestCase;

/** Que escritas do store se anunciam ao stream por push de um dispositivo, e quais ficam caladas. */
final class DashboardStoreUpdatesTest extends MysqlDashboardTestCase
{
    private const DEVICE = 'fbd87c59ba8b';

    /** Fora de `hub:`, porque o `REDIS_HOST` pode ser o da produção, cujas chaves não expiram. */
    private const KEY_ROOT = 'test:dashboard-store-updates';

    /** @var list<RedisClient> */
    private array $clients = [];

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        // Apagar as raízes é do teste, e não de quem depois encontra o lixo.
        foreach ($this->clients as $index => $redis) {
            $root = $this->roots[$index] ?? '';
            if ($root === '') {
                continue;
            }

            // O `KEYS` devolve os nomes já com o prefixo, que o `DEL` voltaria a pôr.
            $keys = array_map(static fn(string $key): string => substr($key, strlen($root) + 1), $redis->keys('*'));
            if ($keys !== []) {
                $redis->del($keys);
            }
        }

        $this->clients = [];
        $this->roots = [];

        parent::tearDown();
    }

    private function store(): DeviceStore
    {
        $root = self::KEY_ROOT . ':' . bin2hex(random_bytes(4));
        $redis = new RedisClient([
            'host' => (string)(getenv('TEST_REDIS_HOST') ?: getenv('REDIS_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('TEST_REDIS_PORT') ?: getenv('REDIS_PORT') ?: 6379),
        ], ['prefix' => $root . ':']);

        try {
            $redis->connect();
        } catch (\Throwable $e) {
            self::markTestSkipped('Needs a reachable Redis: ' . $e->getMessage());
        }

        $this->clients[] = $redis;
        $this->roots[] = $root;

        return new DeviceStore($redis);
    }

    /** @return array{DeviceStore, callable(): int} */
    private function storeCountingNotifications(): array
    {
        $store = $this->store();
        $count = 0;
        $store->updates()->subscribe(self::DEVICE, static function () use (&$count): void {
            $count++;
        });

        // Por referência: uma arrow function capturaria a contagem por valor e daria sempre zero.
        return [$store, static function () use (&$count): int {
            return $count;
        }];
    }

    public function testTelemetryAndEventAppendsAreAnnounced(): void
    {
        [$store, $count] = $this->storeCountingNotifications();

        $store->append(self::DEVICE, 'telemetry', ['type' => 'battery', 'data' => ['percent' => 98]]);
        $store->append(self::DEVICE, 'events', ['type' => 'help_call', 'data' => ['pressType' => 'single']]);

        self::assertSame(2, $count());
    }

    public function testRawAppendsAreNotAnnounced(): void
    {
        [$store, $count] = $this->storeCountingNotifications();

        // Escrita a cada mensagem de gateway e nunca enviada no stream: anunciá-la acordaria
        // todos os streams abertos para dados que ninguém lê.
        $store->append(self::DEVICE, 'raw', ['type' => 'raw', 'data' => []]);

        self::assertSame(0, $count());
    }

    public function testDeviceSeenIsNotAnnounced(): void
    {
        [$store, $count] = $this->storeCountingNotifications();

        // Chamado a cada observação, e não muda o que o stream serve: não pode derrotar a
        // coalescência.
        $store->deviceSeen(self::DEVICE, ['supplier' => 'MOKO', 'model' => 'W6B', 'online' => '1']);

        self::assertSame(0, $count());
    }

    public function testTheCommandLifecycleIsAnnouncedAtEveryStep(): void
    {
        [$store, $count] = $this->storeCountingNotifications();

        $store->recordCommand(self::DEVICE, 'cmd-1', [
            'protocol' => 'moko-w6b',
            'command' => 'test',
            'nativeType' => 'test',
            'expectedReplyTypes' => ['test'],
            'status' => 'queued',
        ]);
        $before = $count();

        $store->markCommand(self::DEVICE, 'cmd-1', ['status' => 'sent']);
        $store->markLatestCommand(self::DEVICE, 'test', ['status' => 'waiting']);
        $store->markCommandReply(self::DEVICE, 'test', null, '', true);

        self::assertSame(1, $before, 'gravar um comando anuncia uma vez');
        self::assertSame(4, $count(), 'cada transição do ciclo de vida anuncia');
    }

    /** Uma varredura acorda só os streams dos aparelhos com comandos que percorreu. */
    public function testSweepsAnnounceOnlyTheDevicesTheyVisit(): void
    {
        $store = $this->store();
        $store->recordCommand(self::DEVICE, 'cmd-1', ['protocol' => 'moko-w6b', 'command' => 'test', 'status' => 'queued']);
        $calls = [];
        $store->updates()->subscribe(self::DEVICE, static function () use (&$calls): void {
            $calls[] = self::DEVICE;
        });
        $store->updates()->subscribe('other-device', static function () use (&$calls): void {
            $calls[] = 'other';
        });

        $store->expireWaitingCommands(3600);

        self::assertSame([self::DEVICE], $calls);
    }

    public function testOtherDevicesAreNotWokenByAnUnrelatedWrite(): void
    {
        [$store, $count] = $this->storeCountingNotifications();

        $store->append('some-other-device', 'telemetry', ['type' => 'battery', 'data' => []]);

        self::assertSame(0, $count());
    }
}
