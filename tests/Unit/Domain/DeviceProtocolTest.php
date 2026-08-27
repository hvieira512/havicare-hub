<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\DeviceProtocol;
use PHPUnit\Framework\TestCase;

final class DeviceProtocolTest extends TestCase
{
    /**
     * A MOKO vende gateways e uma pulseira, e por isso resolver só pelo fornecedor dava à
     * W6R um protocolo de gateway.
     */
    public function testMokoModelsResolveToTheirOwnProtocol(): void
    {
        self::assertSame('moko-mkgw3', DeviceProtocol::forModel('MOKO', 'MKGW3'));
        self::assertSame('moko-mkgw4', DeviceProtocol::forModel('MOKO', 'MKGW4'));
        self::assertSame('moko-w6r', DeviceProtocol::forModel('MOKO', 'W6R'));
    }

    public function testModelLookupIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertSame('moko-w6r', DeviceProtocol::forModel(' moko ', ' w6r '));
        self::assertSame('moko-w6r', DeviceProtocol::forModel('MoKo', 'W6r'));
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
