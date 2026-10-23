<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Hub\Support\TtlCache;
use PHPUnit\Framework\TestCase;

final class TtlCacheTest extends TestCase
{
    public function testAValueIsLoadedOnceWithinTheTtl(): void
    {
        $cache = new TtlCache(3600);
        $loads = 0;
        $load = static function () use (&$loads): int {
            return ++$loads;
        };

        self::assertSame(1, $cache->remember('a', $load));
        self::assertSame(1, $cache->remember('a', $load));
        self::assertSame(2, $cache->remember('b', $load), 'outra chave carrega o seu');
    }

    public function testForgetMakesTheNextReadLoadAgain(): void
    {
        $cache = new TtlCache(3600);
        $cache->remember('a', static fn (): string => 'old');
        $cache->forget('a');

        self::assertSame('new', $cache->remember('a', static fn (): string => 'new'));
    }

    /** O processo corre meses: cheio o mapa, sai a chave carregada há mais tempo. */
    public function testTheOldestKeyIsDroppedOnceTheCapIsReached(): void
    {
        $cache = new TtlCache(3600);
        for ($i = 0; $i < TtlCache::MAX_KEYS; $i++) {
            $cache->remember("d$i", static fn (): string => 'cached');
        }

        $cache->remember('overflow', static fn (): string => 'cached');

        self::assertSame('reloaded', $cache->remember('d0', static fn (): string => 'reloaded'), 'o d0 foi despejado');
        self::assertSame('cached', $cache->remember('d2', static fn (): string => 'reloaded'), 'os outros continuam');
    }
}
