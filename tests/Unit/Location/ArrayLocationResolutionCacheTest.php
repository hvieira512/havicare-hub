<?php

declare(strict_types=1);

namespace Tests\Unit\Location;

use Hub\Location\ArrayLocationResolutionCache;
use PHPUnit\Framework\TestCase;

final class ArrayLocationResolutionCacheTest extends TestCase
{
    // Espelha ArrayLocationResolutionCache::MAX_ENTRIES; o teto é o contrato a prender.
    private const CAP = 5000;

    public function testEvictsTheOldestEntryOnceTheCapIsReached(): void
    {
        $cache = new ArrayLocationResolutionCache();

        for ($i = 0; $i < self::CAP; $i++) {
            $cache->putResolved("k$i", ['lat' => 0.0, 'lng' => 0.0], 3600);
        }

        // Ainda dentro do teto: a primeira chave continua lá.
        self::assertNotNull($cache->get('k0'));

        // A entrada que passa o teto expulsa a mais antiga, não fica retida para sempre.
        $cache->putResolved('overflow', ['lat' => 1.0, 'lng' => 1.0], 3600);

        self::assertNull($cache->get('k0'), 'A entrada mais antiga deveria ter sido despejada');
        self::assertNotNull($cache->get('k1'), 'Uma entrada recente não deveria ser tocada');
        self::assertNotNull($cache->get('overflow'), 'A entrada nova deveria estar presente');
    }
}
