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
        self::assertArrayNotHasKey('categoryOrder', $protocol['dashboard']);
        self::assertArrayNotHasKey('categoryLabels', $protocol['dashboard']);
        self::assertSame('Contactos SOS', $protocol['dashboard']['groupedCapabilities']['sos_contacts']['label']);
        self::assertSame(10, $protocol['dashboard']['groupedCapabilities']['call_whitelist']['limit']);
        self::assertSame(10, $protocol['dashboard']['fieldConstraints']['phonebook']['name']['maxLength']);
        self::assertSame(20, $protocol['dashboard']['fieldConstraints']['phonebook']['phone']['maxLength']);
    }

    public function testProtocolDashboardMetadataDoesNotDefineASecondCapabilityTaxonomy(): void
    {
        foreach (['wonlex-json', 'vivistar-iw', 'four-p-touch'] as $protocolKey) {
            $dashboard = ProtocolRegistry::describe($protocolKey)['dashboard'];
            self::assertArrayNotHasKey('categoryLabels', $dashboard);
            self::assertArrayNotHasKey('categoryOrder', $dashboard);
        }
    }

    public function testWonlexContactMetadataUsesTheGenericContactContract(): void
    {
        $dashboard = ProtocolRegistry::describe('wonlex-json')['dashboard'];

        self::assertSame(10, $dashboard['groupedCapabilities']['phonebook']['limit'] ?? null);
        self::assertSame(10, $dashboard['groupedCapabilities']['sos_contacts']['limit'] ?? null);
        self::assertArrayHasKey('whitelist_enabled', $dashboard['groupedCapabilities']);
        self::assertArrayNotHasKey('call_whitelist', $dashboard['groupedCapabilities']);
        self::assertSame(4, $dashboard['fieldConstraints']['phonebook']['name']['maxLength'] ?? null);
    }

    public function testOnlyProtocolsWithConfigCatalogAreReturnedInTheHelper(): void
    {
        self::assertContains('four-p-touch', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertContains('vivistar-iw', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertContains('wonlex-json', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertNotContains('voerka-ncs', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertNotContains('qinglanst-radar', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertNotContains('moko-mkgw3', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertNotContains('monit-mecs-pro-ble', ProtocolRegistry::protocolsWithConfigCatalog());
    }

    public function testForSupplierIsDerivedFromTheRegistryTable(): void
    {
        self::assertSame('four-p-touch', ProtocolRegistry::forSupplier('4P Touch'));
        self::assertSame('vivistar-iw', ProtocolRegistry::forSupplier('Vivistar'));
        self::assertSame('wonlex-json', ProtocolRegistry::forSupplier('Wonlex'));
        self::assertSame('moko-mkgw3', ProtocolRegistry::forSupplier('MOKO'));
        self::assertSame('monit-mecs-pro-ble', ProtocolRegistry::forSupplier('MONIT'));
        self::assertSame('', ProtocolRegistry::forSupplier('Unknown'));
    }
}
