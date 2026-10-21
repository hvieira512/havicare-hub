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

    /** A W6 não tem toque longo: o firmware dá simples, duplo e triplo. */
    public function testTheBraceletsDeclareTheirPressModes(): void
    {
        self::assertSame(['single', 'double', 'long'], ProtocolRegistry::describe('moko-w6b')['helpCallPressModes']);
        self::assertSame(['single', 'double', 'triple'], ProtocolRegistry::describe('moko-w6')['helpCallPressModes']);
        self::assertSame([], ProtocolRegistry::describe('four-p-touch')['helpCallPressModes']);
    }

    public function testOnlyProtocolsWithConfigCatalogAreReturnedInTheHelper(): void
    {
        self::assertNotContains('moko-mkgw3', ProtocolRegistry::protocolsWithConfigCatalog());
        // O MONIT tem catálogo sem ter downlink: o flag diz se há o que configurar, e não se a
        // alteração viaja.
        self::assertContains('monit-mecs-pro-ble', ProtocolRegistry::protocolsWithConfigCatalog());
    }

    public function testForSupplierPicksTheProtocolOfTheDeviceType(): void
    {
        self::assertSame('moko-mkgw3', ProtocolRegistry::forSupplier('MOKO', 'gateway'));
        self::assertSame('monit-mecs-pro-ble', ProtocolRegistry::forSupplier('MONIT', 'diaper_sensor'));
        self::assertSame('joytech-et', ProtocolRegistry::forSupplier('JoyTech', 'thermometer'));
        self::assertSame('veepoo-ble', ProtocolRegistry::forSupplier('Wonlex', 'bracelet'));
        self::assertSame('wonlex-json', ProtocolRegistry::forSupplier('Wonlex', 'watch'));
        self::assertSame('', ProtocolRegistry::forSupplier('Unknown', 'watch'));
    }

    /** A MOKO vende gateways e pulseiras: só pelo fornecedor, a W6B ficava com protocolo de gateway. */
    public function testMokoModelsResolveToTheirOwnProtocol(): void
    {
        self::assertSame('moko-mkgw3', ProtocolRegistry::forModel('MOKO', 'MKGW3'));
        self::assertSame('moko-mkgw4', ProtocolRegistry::forModel('MOKO', 'MKGW4'));
        self::assertSame('moko-w6b', ProtocolRegistry::forModel('MOKO', 'W6B'));
    }

    public function testModelLookupIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertSame('moko-w6b', ProtocolRegistry::forModel(' moko ', ' w6b '));
        self::assertSame('moko-w6b', ProtocolRegistry::forModel('MoKo', 'W6b'));
    }

    public function testSingleProtocolSuppliersStillResolveBySupplier(): void
    {
        self::assertSame('voerka-ncs', ProtocolRegistry::forModel('Voerka', 'W812'));
        self::assertSame('monit-mecs-pro-ble', ProtocolRegistry::forModel('MONIT', 'MECS-PRO'));
    }

    /** O MKGW-mini fala o JSON do MKGW3, e um modelo MOKO por declarar cai no mesmo. */
    public function testUnknownMokoModelFallsBackToTheSupplierProtocol(): void
    {
        self::assertSame('moko-mkgw3', ProtocolRegistry::forModel('MOKO', 'SOMETHING-NEW'));
        self::assertSame('moko-mkgw3', ProtocolRegistry::forModel('MOKO', 'MKGW-mini 03-20D'));
    }

    public function testUnknownSupplierHasNoProtocol(): void
    {
        self::assertSame('', ProtocolRegistry::forModel('Nobody', 'X1'));
    }

    /** A Wonlex vende relógios TCP, e esta pulseira fala BLE. */
    public function testTheMf91BraceletSpeaksBleAndNotTheWonlexWatchProtocol(): void
    {
        self::assertSame('veepoo-ble', ProtocolRegistry::forModel('Wonlex', 'MF91'));
    }
}
