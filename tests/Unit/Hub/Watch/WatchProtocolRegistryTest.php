<?php

declare(strict_types=1);

namespace Tests\Unit\Hub\Watch;

use Hub\Watch\WatchProtocolRegistry;
use PHPUnit\Framework\TestCase;

final class WatchProtocolRegistryTest extends TestCase
{
    public function testCommandMetadataUsesSupplierSpecificFallbacks(): void
    {
        $registry = new WatchProtocolRegistry();

        self::assertSame([
            'nativeType' => 'BPXY',
            'protocol' => 'vivistar-iw',
            'ident' => '080835',
        ], $registry->commandMetadata('IWBPXY,861265061009822,080835,1#'));
    }
}
