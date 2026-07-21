<?php

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
        self::assertSame(['intervals', 'contacts', 'alerts', 'health', 'system'], $protocol['dashboard']['categoryOrder']);
        self::assertSame('Contactos', $protocol['dashboard']['categoryLabels']['contacts']);
        self::assertSame('Alarmes', $protocol['dashboard']['categoryLabels']['alerts']);
        self::assertSame('Contactos SOS', $protocol['dashboard']['groupedCapabilities']['sos_contacts']['label']);
        self::assertSame(10, $protocol['dashboard']['groupedCapabilities']['call_whitelist']['limit']);
        self::assertSame(10, $protocol['dashboard']['fieldConstraints']['phonebook']['name']['maxLength']);
        self::assertSame(20, $protocol['dashboard']['fieldConstraints']['phonebook']['phone']['maxLength']);
    }

    public function testAllKnownProtocolsShareTheSameAlertsLabel(): void
    {
        foreach (['wonlex-json', 'vivistar-iw', 'four-p-touch'] as $protocolKey) {
            self::assertSame('Alarmes', ProtocolRegistry::describe($protocolKey)['dashboard']['categoryLabels']['alerts']);
        }
    }

    public function testOnlyProtocolsWithConfigCatalogAreReturnedInTheHelper(): void
    {
        self::assertContains('four-p-touch', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertContains('vivistar-iw', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertContains('wonlex-json', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertNotContains('voerka-ncs', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertNotContains('qinglanst-radar', ProtocolRegistry::protocolsWithConfigCatalog());
    }

    public function testForSupplierIsDerivedFromTheRegistryTable(): void
    {
        self::assertSame('four-p-touch', ProtocolRegistry::forSupplier('4P Touch'));
        self::assertSame('vivistar-iw', ProtocolRegistry::forSupplier('Vivistar'));
        self::assertSame('wonlex-json', ProtocolRegistry::forSupplier('Wonlex'));
        self::assertSame('', ProtocolRegistry::forSupplier('Unknown'));
    }
}
