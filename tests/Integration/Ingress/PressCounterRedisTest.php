<?php

declare(strict_types=1);

namespace Tests\Integration\Ingress;

use Hub\Ingress\Mqtt\Gateway\RedisObservationStateStore;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/** O contador de toques da W6B no Redis a sério, onde dois gateways o atualizam ao mesmo tempo. */
final class PressCounterRedisTest extends TestCase
{
    private const KEY = 'fbd87c59ba8b:press:single';

    /** Não começa por `hub:`: o `REDIS_HOST` pode ser o de produção. */
    private string $root = '';

    /** @var array{host: string, port: int} */
    private array $parameters;

    private ?RedisClient $redis = null;

    protected function setUp(): void
    {
        $this->parameters = [
            'host' => (string)(getenv('TEST_REDIS_HOST') ?: getenv('REDIS_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('TEST_REDIS_PORT') ?: getenv('REDIS_PORT') ?: 6379),
        ];
        $this->redis = new RedisClient($this->parameters);
        try {
            $this->redis->connect();
        } catch (\Throwable $e) {
            self::markTestSkipped('Needs a reachable Redis: ' . $e->getMessage());
        }
        $this->root = 'test:press:' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $keys = $this->redis?->keys($this->root . '*') ?? [];
        if ($keys !== []) {
            $this->redis?->del($keys);
        }
    }

    public function testTwoGatewaysDeliveringTheSameNewCounterAtOncePublishOnce(): void
    {
        $other = new RedisObservationStateStore(new RedisClient($this->parameters), $this->root);
        $other->advanceCounter(self::KEY, 69);

        // O outro gateway entrega o mesmo 70 logo a seguir ao primeiro comando deste.
        $interleaved = new class ($this->parameters) extends RedisClient {
            public ?\Closure $afterFirstCommand = null;

            /** @param array<int, mixed> $arguments */
            public function __call($commandID, $arguments)
            {
                $result = parent::__call($commandID, $arguments);
                $hook = $this->afterFirstCommand;
                $this->afterFirstCommand = null;
                if ($hook !== null) {
                    $hook();
                }
                return $result;
            }
        };
        $otherResult = null;
        $interleaved->afterFirstCommand = static function () use ($other, &$otherResult): void {
            $otherResult = $other->advanceCounter(self::KEY, 70);
        };

        $result = (new RedisObservationStateStore($interleaved, $this->root))->advanceCounter(self::KEY, 70);

        self::assertCount(1, array_filter([$result, $otherResult], static fn(?int $previous): bool => $previous !== null));
    }

    public function testOnlyARiseOrAResetNearZeroIsAPress(): void
    {
        $store = new RedisObservationStateStore($this->redis, $this->root);

        self::assertNull($store->advanceCounter(self::KEY, 37), 'a primeira observação é a linha de base');
        self::assertSame(37, $store->advanceCounter(self::KEY, 39));
        self::assertNull($store->advanceCounter(self::KEY, 39));
        self::assertNull($store->advanceCounter(self::KEY, 38), 'um gateway atrasado não é toque');
        self::assertNull($store->advanceCounter(self::KEY, 39), 'nem o deixa guardado');
        self::assertSame(0, $store->advanceCounter(self::KEY, 1), 'perto do zero é um reinício');
        self::assertGreaterThan(0, $this->redis?->ttl($this->root . ':condition:' . self::KEY));
    }
}
