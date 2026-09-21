<?php

declare(strict_types=1);

namespace Tests\Unit\Hub\Tcp;

use Hub\Device\Tcp\TcpProtocolRegistry;
use PHPUnit\Framework\TestCase;

final class TcpProtocolRegistryTest extends TestCase
{
    public function testCommandMetadataUsesSupplierSpecificFallbacks(): void
    {
        $registry = new TcpProtocolRegistry();

        self::assertSame([
            'nativeType' => 'BPXY',
            'protocol' => 'vivistar-iw',
            'ident' => '080835',
        ], $registry->commandMetadata('IWBPXY,861265061009822,080835,1#'));
    }
}
