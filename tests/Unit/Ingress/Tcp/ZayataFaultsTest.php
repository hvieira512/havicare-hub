<?php

declare(strict_types=1);

namespace Tests\Unit\Ingress\Tcp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\ZayataFixtures;

/** As cinco avarias que o aparelho sabe reportar, uma a uma. */
final class ZayataFaultsTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function faults(): iterable
    {
        yield 'tray rotation' => [0x8121, 'rotation'];
        yield 'tray reset' => [0x8122, 'tray_reset'];
        yield 'pusher' => [0x8123, 'pusher'];
        yield 'compartment door' => [0x8124, 'cell_door'];
        yield 'keys' => [0x8125, 'keys'];
    }

    #[DataProvider('faults')]
    public function testEachFaultBecomesItsOwnEvent(int $tag, string $fault): void
    {
        self::assertSame(['fault' => $fault], ZayataFixtures::features(0x87, [$tag => "\x01"])['device_fault'] ?? null);
    }

    /** Um estado a zero é o normal, e não uma avaria a zero. */
    public function testAHealthyStateIsNotAFault(): void
    {
        self::assertArrayNotHasKey('device_fault', ZayataFixtures::features(0x87, [0x8121 => "\x00"]));
    }
}
