<?php

declare(strict_types=1);

namespace Tests\Integration\Ingress;

use Hub\Ingress\Mqtt\JoyTech\BloodPressureHistoryMarks;
use Hub\Ingress\Mqtt\JoyTech\RecentMeasurementKeys;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/** O que os aparelhos JoyTech guardam entre ligações, no Redis a sério e não no substituto em memória. */
final class JoyTechRedisStoresTest extends TestCase
{
    private const MONITOR = '748b34837b1e';

    /** Não começa por `hub:`: o `REDIS_HOST` pode ser o de produção. */
    private string $root = '';

    private ?RedisClient $redis = null;

    protected function setUp(): void
    {
        $this->redis = new RedisClient([
            'host' => (string)(getenv('TEST_REDIS_HOST') ?: getenv('REDIS_HOST') ?: '127.0.0.1'),
            'port' => (int)(getenv('TEST_REDIS_PORT') ?: getenv('REDIS_PORT') ?: 6379),
        ]);
        try {
            $this->redis->connect();
        } catch (\Throwable $e) {
            self::markTestSkipped('Needs a reachable Redis: ' . $e->getMessage());
        }
        $this->root = 'test:joytech:' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $keys = $this->redis?->keys($this->root . '*') ?? [];
        if ($keys !== []) {
            $this->redis?->del($keys);
        }
    }

    /** A trama binária é o membro, e o que a marca ultrapassa sai da lista. */
    public function testTheMarkAndTheLiveResultsSurviveInRedis(): void
    {
        $marks = new BloodPressureHistoryMarks($this->redis, $this->root);
        $older = "\x00\x88\x00\x49\x4c\x00\x1a\x0a\x10\x0e\x33\x01";
        $newer = "\x00\x7b\x00\x57\x59\x00\x1a\x0a\x10\x0f\x00\x01";
        $marks->rememberLive(self::MONITOR, 1, $older, 1000);
        $marks->rememberLive(self::MONITOR, 1, $newer, 2000);

        $marks->advance(self::MONITOR, 1, 1500);
        $marks->advance(self::MONITOR, 1, 1200);

        self::assertSame(1500, $marks->mark(self::MONITOR, 1));
        self::assertSame(0, $marks->mark(self::MONITOR, 2));
        self::assertFalse($marks->wasLive(self::MONITOR, 1, $older));
        self::assertTrue($marks->wasLive(self::MONITOR, 1, $newer));
        // Só a lista dos ao vivo pode crescer; a marca fica, para a memória não voltar a sair toda.
        self::assertGreaterThan(0, $this->redis?->ttl($this->root . ':live:' . self::MONITOR . ':1'));
        self::assertSame(-1, $this->redis?->ttl($this->root . ':history-mark:' . self::MONITOR));
    }

    public function testTheThermometerKeepsTheLastSixty(): void
    {
        $seen = new RecentMeasurementKeys($this->redis, $this->root);
        foreach (range(1, 61) as $i) {
            $seen->add(self::MONITOR, "key-{$i}");
        }

        $all = $seen->all(self::MONITOR);
        self::assertCount(60, $all);
        self::assertSame('key-61', $all[0]);
        self::assertNotContains('key-1', $all);
    }
}
