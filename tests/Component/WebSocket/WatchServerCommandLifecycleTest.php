<?php

declare(strict_types=1);

namespace Tests\Component\WebSocket;

use App\Redis\Client as RedisClient;
use App\WebSocket\WatchServer;
use PHPUnit\Framework\TestCase;

final class WatchServerCommandLifecycleTest extends TestCase
{
    public function testSweepCommandTimeoutsMarksTimeoutAndClearsSessionState(): void
    {
        $redis = new class extends RedisClient {
            public array $states = [];
            public function __construct() {}
            public function isAvailable(): bool { return true; }
            public function commandStatePush(array $state): string
            {
                $this->states[] = $state;
                return '1-0';
            }
        };

        $server = new WatchServer(null, $redis);

        $this->setPrivateProperty($server, 'pendingCommands', [
            'req-1' => [
                'resourceId' => 10,
                'imei' => '865028000000306',
                'type' => 'dnHeartRate',
                'feature' => 'heart_rate',
                'ident' => '123456',
                'protocol' => 'wonlex-json',
                'deadlineAt' => (int)(round(microtime(true) * 1000) - 1000),
            ],
        ]);
        $this->setPrivateProperty($server, 'sessions', [
            10 => [
                'lastCommandType' => 'dnHeartRate',
                'lastCommandIdent' => '123456',
                'lastCommandRequestId' => 'req-1',
                'lastCommandFeature' => 'heart_rate',
            ],
        ]);

        $timedOut = $server->sweepCommandTimeouts();

        self::assertSame(1, $timedOut);
        self::assertCount(1, $redis->states);
        self::assertSame('timeout', $redis->states[0]['state']);
        self::assertSame('req-1', $redis->states[0]['requestId']);

        $sessions = $this->getPrivateProperty($server, 'sessions');
        self::assertNull($sessions[10]['lastCommandType']);
        self::assertNull($sessions[10]['lastCommandIdent']);
        self::assertNull($sessions[10]['lastCommandRequestId']);
        self::assertNull($sessions[10]['lastCommandFeature']);

        $pending = $this->getPrivateProperty($server, 'pendingCommands');
        self::assertSame([], $pending);
    }

    public function testPendingCommandsForClosedResourceAreMarkedFailed(): void
    {
        $redis = new class extends RedisClient {
            public array $states = [];
            public function __construct() {}
            public function isAvailable(): bool { return true; }
            public function commandStatePush(array $state): string
            {
                $this->states[] = $state;
                return '1-0';
            }
        };

        $server = new WatchServer(null, $redis);
        $this->setPrivateProperty($server, 'pendingCommands', [
            'req-closed' => [
                'resourceId' => 99,
                'imei' => '865028000000306',
                'type' => 'dnHeartRate',
                'feature' => 'heart_rate',
                'ident' => '654321',
                'protocol' => 'wonlex-json',
                'deadlineAt' => (int)(round(microtime(true) * 1000) + 60000),
            ],
        ]);

        $ref = new \ReflectionClass($server);
        $method = $ref->getMethod('failPendingCommandsForResource');
        $method->invoke($server, 99, 'device_disconnected_before_ack');

        self::assertCount(1, $redis->states);
        self::assertSame('failed', $redis->states[0]['state']);
        self::assertSame('device_disconnected_before_ack', $redis->states[0]['reason']);
        self::assertSame('req-closed', $redis->states[0]['requestId']);
        self::assertSame([], $this->getPrivateProperty($server, 'pendingCommands'));
    }

    private function setPrivateProperty(object $target, string $name, mixed $value): void
    {
        $ref = new \ReflectionClass($target);
        $prop = $ref->getProperty($name);
        $prop->setValue($target, $value);
    }

    private function getPrivateProperty(object $target, string $name): mixed
    {
        $ref = new \ReflectionClass($target);
        $prop = $ref->getProperty($name);
        return $prop->getValue($target);
    }
}
