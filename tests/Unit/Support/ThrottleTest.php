<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Hub\Support\Throttle;
use PHPUnit\Framework\TestCase;

final class ThrottleTest extends TestCase
{
    public function testOncePerWindowAndKey(): void
    {
        $throttle = new Throttle();

        self::assertTrue($throttle->allow('radar-1', 30, 0));
        self::assertFalse($throttle->allow('radar-1', 30, 29.999));
        self::assertTrue($throttle->allow('radar-2', 30, 5), 'outra chave não é calada pela primeira');
        self::assertTrue($throttle->allow('radar-1', 30, 30));
    }

    public function testAWindowOfZeroLetsEverythingThrough(): void
    {
        $throttle = new Throttle();

        self::assertTrue($throttle->allow('radar-1', 0, 0));
        self::assertTrue($throttle->allow('radar-1', 0, 0));
    }

    /** O processo corre meses: cheio o mapa, sai a chave deixada passar há mais tempo. */
    public function testTheLeastRecentlyAllowedKeyIsDroppedOnceTheCapIsReached(): void
    {
        $throttle = new Throttle();
        for ($i = 0; $i < Throttle::MAX_KEYS; $i++) {
            $throttle->allow("d$i", 60, 0);
        }
        self::assertFalse($throttle->allow('d0', 60, 1));

        self::assertTrue($throttle->allow('overflow', 60, 1));
        self::assertTrue($throttle->allow('d0', 60, 2), 'o d0 foi despejado');
        self::assertFalse($throttle->allow('d2', 60, 2), 'os outros continuam');
    }
}
