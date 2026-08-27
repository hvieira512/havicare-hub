<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use Hub\Dashboard\DashboardStore;
use Predis\Client as RedisClient;
use Tests\Support\MysqlDashboardTestCase;

/**
 * O stream de um dispositivo é por push, e por isso só vê uma mudança se o store a anunciar.
 * Isto prende quais as escritas que anunciam e quais é que ficam caladas.
 */
final class DashboardStoreUpdatesTest extends MysqlDashboardTestCase
{
    private const DEVICE = 'fbd87c59ba8b';

    private function store(): DashboardStore
    {
        $redis = new RedisClient([
            'host' => (string)(getenv('TEST_REDIS_HOST') ?: getenv('REDIS_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('TEST_REDIS_PORT') ?: getenv('REDIS_PORT') ?: 6379),
        ]);

        try {
            $redis->connect();
        } catch (\Throwable $e) {
            self::markTestSkipped('Needs a reachable Redis: ' . $e->getMessage());
        }

        return new DashboardStore($redis, 100, 'hub:test:' . bin2hex(random_bytes(4)));
    }

    /** @return array{DashboardStore, callable(): int} */
    private function storeCountingNotifications(): array
    {
        $store = $this->store();
        $count = 0;
        $store->updates()->subscribe(self::DEVICE, static function () use (&$count): void {
            $count++;
        });

        // Por referência: uma arrow function capturava a contagem por valor e
        // always report zero.
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

        // Escrita a cada mensagem de gateway e nunca enviada no stream: anunciá-la acordava
        // todos os streams abertos para dados que ninguém lê.
        $store->append(self::DEVICE, 'raw', ['type' => 'raw', 'data' => []]);

        self::assertSame(0, $count());
    }

    public function testDeviceSeenIsNotAnnounced(): void
    {
        [$store, $count] = $this->storeCountingNotifications();

        // Chamado a cada observação, e não muda o que o stream serve: não pode derrotar a
        // coalescência.
        $store->deviceSeen(self::DEVICE, ['supplier' => 'MOKO', 'model' => 'W6R', 'online' => '1']);

        self::assertSame(0, $count());
    }

    public function testTheCommandLifecycleIsAnnouncedAtEveryStep(): void
    {
        [$store, $count] = $this->storeCountingNotifications();

        $store->recordCommand(self::DEVICE, 'cmd-1', [
            'protocol' => 'moko-w6r',
            'command' => 'test',
            'status' => 'queued',
        ]);
        $before = $count();

        $store->markCommand(self::DEVICE, 'cmd-1', ['status' => 'sent']);
        $store->markLatestCommand(self::DEVICE, 'test', ['status' => 'delivered']);
        $store->markCommandReply(self::DEVICE, 'test', null, '', true);

        self::assertSame(1, $before, 'recording a command should announce once');
        self::assertSame(4, $count(), 'every lifecycle transition should announce');
    }

    public function testSweepsAnnounceToEveryListenerSinceTheyNameNoDevice(): void
    {
        $store = $this->store();
        $calls = [];
        $store->updates()->subscribe(self::DEVICE, static function () use (&$calls): void {
            $calls[] = self::DEVICE;
        });
        $store->updates()->subscribe('other-device', static function () use (&$calls): void {
            $calls[] = 'other';
        });

        $store->expireWaitingCommands(3600);

        sort($calls);
        self::assertSame(['fbd87c59ba8b', 'other'], $calls);
    }

    public function testOtherDevicesAreNotWokenByAnUnrelatedWrite(): void
    {
        [$store, $count] = $this->storeCountingNotifications();

        $store->append('some-other-device', 'telemetry', ['type' => 'battery', 'data' => []]);

        self::assertSame(0, $count());
    }
}
