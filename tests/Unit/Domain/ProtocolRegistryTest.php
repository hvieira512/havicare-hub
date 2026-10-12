<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

final class ProtocolRegistryTest extends TestCase
{
    public function testDescribeReturnsCanonicalMetadataForFourPTouch(): void
    {
        $protocol = ProtocolRegistry::describe('four-p-touch');

        self::assertSame('four-p-touch', $protocol['protocol']);
        self::assertSame('4P Touch', $protocol['label']);
        self::assertSame('watch', $protocol['deviceType']);
        self::assertTrue($protocol['supportsConfigCatalog']);
    }

    public function testOnlyProtocolsWithConfigCatalogAreReturnedInTheHelper(): void
    {
        self::assertNotContains('moko-mkgw3', ProtocolRegistry::protocolsWithConfigCatalog());
        // O MONIT tem catálogo sem ter downlink: o flag diz se há o que configurar, e não se a
        // alteração viaja.
        self::assertContains('monit-mecs-pro-ble', ProtocolRegistry::protocolsWithConfigCatalog());
    }

    public function testForSupplierIsDerivedFromTheRegistryTable(): void
    {
        self::assertSame('moko-mkgw3', ProtocolRegistry::forSupplier('MOKO'));
        self::assertSame('monit-mecs-pro-ble', ProtocolRegistry::forSupplier('MONIT'));
        self::assertSame('', ProtocolRegistry::forSupplier('Unknown'));
    }
}
