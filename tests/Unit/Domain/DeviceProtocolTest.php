<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\DeviceProtocol;
use PHPUnit\Framework\TestCase;

final class DeviceProtocolTest extends TestCase
{
    /**
     * A MOKO vende gateways e uma pulseira, e por isso resolver só pelo fornecedor dava à
     * W6B um protocolo de gateway.
     */
    public function testMokoModelsResolveToTheirOwnProtocol(): void
    {
        self::assertSame('moko-mkgw3', DeviceProtocol::forModel('MOKO', 'MKGW3'));
        self::assertSame('moko-mkgw4', DeviceProtocol::forModel('MOKO', 'MKGW4'));
        self::assertSame('moko-w6b', DeviceProtocol::forModel('MOKO', 'W6B'));
    }

    public function testModelLookupIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertSame('moko-w6b', DeviceProtocol::forModel(' moko ', ' w6b '));
        self::assertSame('moko-w6b', DeviceProtocol::forModel('MoKo', 'W6b'));
    }

    public function testSingleProtocolSuppliersStillResolveBySupplier(): void
    {
        self::assertSame('voerka-ncs', DeviceProtocol::forModel('Voerka', 'W812'));
        self::assertSame('monit-mecs-pro-ble', DeviceProtocol::forModel('MONIT', 'MECS-PRO'));
    }

    public function testUnknownMokoModelFallsBackToTheSupplierProtocol(): void
    {
        self::assertSame('moko-mkgw3', DeviceProtocol::forModel('MOKO', 'SOMETHING-NEW'));
    }

    public function testUnknownSupplierHasNoProtocol(): void
    {
        self::assertSame('', DeviceProtocol::forModel('Nobody', 'X1'));
    }
}
