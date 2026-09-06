<?php

declare(strict_types=1);

namespace Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;

/**
 * O `deviceId` de um 4P Touch deriva do IMEI por `substr`, portanto é dedutível. Se o ramo
 * `four-p-touch` do `resolve()` não restringir o tipo, um frame de relógio autentica-se como
 * um radar ou um NCS que partilhe aquele alias — como os ramos `ncs` e `radar` já impedem.
 */
final class WhitelistFourPTouchResolveTest extends TestCase
{
    public function testDoesNotResolveToADeviceOfAnotherType(): void
    {
        $whitelist = IngressFixtures::whitelist([
            'radar-1' => IngressFixtures::radar() + ['deviceId' => 'shared-alias'],
        ]);

        self::assertNull(
            $whitelist->resolve('some-unknown-imei', 'four-p-touch', 'shared-alias'),
            'um frame de 4P Touch não se pode fazer passar por um radar que partilhe o alias',
        );
    }

    public function testStillResolvesToAWatchWithThatAlias(): void
    {
        $whitelist = IngressFixtures::whitelist([
            'watch-1' => IngressFixtures::device('4P Touch', '4P-TOUCH', 'watch') + ['deviceId' => 'shared-alias'],
        ]);

        $resolved = $whitelist->resolve('some-unknown-imei', 'four-p-touch', 'shared-alias');

        self::assertSame('watch-1', $resolved['imei'] ?? null);
    }
}
