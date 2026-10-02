<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

/** A forma pública destas duas depende do aparelho, e quem a fixa é o contrato da capacidade. */
final class ContactsPublicFormTest extends TestCase
{
    public function testTheCallWhitelistKeepsNamesOnVivistarAndFlattensToNumbersElsewhere(): void
    {
        $registry = new CapabilityRegistry();

        self::assertSame(
            [['name' => 'Ana', 'phone' => '911'], ['name' => '', 'phone' => '922']],
            $registry->fromNative('vivistar-iw', 'call_whitelist', 'call_whitelist', [
                'contacts' => [['name' => 'Ana', 'phone' => '911'], ['name' => '', 'phone' => '922']],
            ]),
        );

        // As tramas `WHITELIST1`/`WHITELIST2` do 4P Touch só transportam números.
        self::assertSame(
            ['911', '922'],
            $registry->fromNative('four-p-touch', 'call_whitelist', 'whitelistGroup1', ['numbers' => ['911', '922']]),
        );

        // Um payload antigo guardado com nomes não muda a forma pública do aparelho.
        self::assertSame(
            ['911'],
            $registry->fromNative('four-p-touch', 'call_whitelist', 'call_whitelist', [
                'contacts' => [['name' => 'Ana', 'phone' => '911']],
            ]),
        );
    }

    public function testTheSosContactsAreAlwaysPlainNumbers(): void
    {
        $registry = new CapabilityRegistry();

        $cases = [
            ['vivistar-iw', 'sosContacts', ['numbers' => ['911', '922']]],
            ['four-p-touch', 'sosContacts', ['numbers' => ['911', '922']]],
            ['wonlex-json', 'SOSNumber', ['sosNumbers' => [
                ['name' => 'Ana', 'phone' => '911'],
                ['name' => '', 'phone' => '922'],
            ]]],
        ];

        foreach ($cases as [$protocol, $nativeKey, $desired]) {
            self::assertSame(
                ['911', '922'],
                $registry->fromNative($protocol, 'sos_contacts', $nativeKey, $desired),
                $protocol,
            );
        }
    }
}
