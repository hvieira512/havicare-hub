<?php

declare(strict_types=1);

namespace Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Tests\Support\Doubles\IngressFixtures;

/** O `resolve()` em memória: cada protocolo com alias procura só no seu tipo de aparelho. */
final class WhitelistResolveTest extends TestCase
{
    public function testNcsResolvesByDeviceIdOfAnNcsOnly(): void
    {
        $whitelist = IngressFixtures::whitelist([
            'ncs-1' => IngressFixtures::device('Voerka', 'NCS', 'ncs') + ['deviceId' => 'gateway-uid'],
            'radar-1' => IngressFixtures::radar() + ['deviceId' => 'radar-uid'],
        ]);

        self::assertSame('ncs-1', $whitelist->resolve('unknown', 'ncs', 'gateway-uid')['imei'] ?? null);
        self::assertNull($whitelist->resolve('unknown', 'ncs', 'radar-uid'));
    }

    public function testRadarResolvesByDeviceIdOrByItsOwnImei(): void
    {
        $whitelist = IngressFixtures::whitelist([
            'radar-1' => IngressFixtures::radar() + ['deviceId' => 'radar-uid'],
        ]);

        self::assertSame('radar-1', $whitelist->resolve('unknown', 'qinglanst-radar', 'radar-uid')['imei'] ?? null);
        self::assertSame('radar-1', $whitelist->resolve('unknown', 'qinglanst', 'radar-1')['imei'] ?? null);
    }

    public function testWatchAndNcsDoNotResolveByAnotherImei(): void
    {
        $whitelist = IngressFixtures::whitelist([
            'watch-1' => IngressFixtures::device('4P Touch', '4P-TOUCH', 'watch') + ['deviceId' => 'watch-uid'],
            'ncs-1' => IngressFixtures::device('Voerka', 'NCS', 'ncs') + ['deviceId' => 'gateway-uid'],
        ]);

        self::assertNull($whitelist->resolve('unknown', 'four-p-touch', 'watch-1'));
        self::assertNull($whitelist->resolve('unknown', 'ncs', 'ncs-1'));
    }

    public function testAProtocolWithoutAliasesResolvesOnlyTheExactImei(): void
    {
        $whitelist = IngressFixtures::whitelist([
            'watch-1' => IngressFixtures::device('4P Touch', '4P-TOUCH', 'watch') + ['deviceId' => 'watch-uid'],
        ]);

        self::assertNull($whitelist->resolve('unknown', 'wonlex-json', 'watch-uid'));
        self::assertSame('watch-1', $whitelist->resolve('watch-1', 'wonlex-json')['imei'] ?? null);
    }
}
