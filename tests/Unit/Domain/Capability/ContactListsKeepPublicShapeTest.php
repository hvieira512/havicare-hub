<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Domain\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

/**
 * O ciclo de vida guarda a forma pública e volta a passá-la pelo `fromNative`. Uma lista de números
 * já é essa forma: lida como nativa, saía vazia e o relógio parecia não ter os contactos.
 */
final class ContactListsKeepPublicShapeTest extends TestCase
{
    public function testTheFourPTouchSosAndWhitelistKeepTheirNumbers(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            ['+351938854803'],
            $registry->fromNative('four-p-touch', 'sos_contacts', 'sosContacts', ['+351938854803']),
        );
        self::assertSame(
            ['+351938854803'],
            $registry->fromNative('four-p-touch', 'call_whitelist', 'whitelistGroup1', ['+351938854803']),
        );
    }

    public function testTheNativeShapeStillReads(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            ['+351938854803'],
            $registry->fromNative('four-p-touch', 'sos_contacts', 'sosContacts', ['numbers' => ['+351938854803', '', '']]),
        );
        self::assertSame(
            [],
            $registry->fromNative('four-p-touch', 'call_whitelist', 'whitelistGroup2', ['numbers' => []]),
        );
    }
}
