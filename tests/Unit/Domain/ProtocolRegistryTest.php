<?php

namespace Tests\Unit\Domain;

use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

final class ProtocolRegistryTest extends TestCase
{
    /**
     * O que a dashboard precisa para desenhar os campos saiu daqui para o
     * `ProtocolDashboardMeta`, e as asserções que o cobriam foram com ele: ver o
     * `tests/Unit/Api/Http/ProtocolDashboardMetaTest.php`.
     */
    public function testDescribeReturnsCanonicalMetadataForFourPTouch(): void
    {
        $protocol = ProtocolRegistry::describe('four-p-touch');

        self::assertSame('four-p-touch', $protocol['protocol']);
        self::assertSame('4P Touch', $protocol['label']);
        self::assertSame('watch', $protocol['deviceType']);
        self::assertTrue($protocol['supportsConfigCatalog']);
    }

    /** O domínio deixou de decidir com que aspecto fica um campo. */
    public function testDescribeCarriesNoPresentationConcerns(): void
    {
        foreach (ProtocolRegistry::keys() as $protocolKey) {
            self::assertSame(
                ['protocol', 'label', 'deviceType', 'supportsConfigCatalog'],
                array_keys(ProtocolRegistry::describe($protocolKey)),
                "O `describe('{$protocolKey}')` voltou a trazer apresentação consigo."
            );
        }
    }

    public function testOnlyProtocolsWithConfigCatalogAreReturnedInTheHelper(): void
    {
        self::assertContains('four-p-touch', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertContains('vivistar-iw', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertContains('wonlex-json', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertNotContains('voerka-ncs', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertNotContains('qinglanst-radar', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertNotContains('moko-mkgw3', ProtocolRegistry::protocolsWithConfigCatalog());
        self::assertNotContains('moko-mkgw4', ProtocolRegistry::protocolsWithConfigCatalog());
        // O MONIT tem catálogo sem ter downlink: a sensibilidade dos alertas é uma
        // configuração aplicada pelo hub. O flag diz se há o que configurar, e não se a
        // alteração viaja -- isso é de cada capacidade, pelo `HubAppliedCapability`.
        self::assertContains('monit-mecs-pro-ble', ProtocolRegistry::protocolsWithConfigCatalog());
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
