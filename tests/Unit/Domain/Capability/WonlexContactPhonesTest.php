<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Domain\Capability\Contacts\WonlexContactCodec;
use PHPUnit\Framework\TestCase;

/** Uma lista de contactos Wonlex — números soltos ou objetos — lê-se como os números públicos. */
final class WonlexContactPhonesTest extends TestCase
{
    public function testStringsAndContactsBecomeTheirPublicPhones(): void
    {
        self::assertSame(
            ['+351912345678', '+34600111222', '213456789'],
            WonlexContactCodec::phones([
                '+351 912 345 678',
                ['phone' => '600111222', 'areaCode' => '34'],
                ['publicPhone' => '213456789'],
            ]),
        );
    }

    public function testRepeatedAndEmptyPhonesAreDropped(): void
    {
        self::assertSame(
            ['+351912345678'],
            WonlexContactCodec::phones(['+351912345678', ['phone' => '912345678', 'areaCode' => '351'], '', ['name' => 'X']]),
        );
    }
}
