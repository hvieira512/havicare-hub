<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Domain\Capability\Contacts\PhonebookCapability;
use PHPUnit\Framework\TestCase;

/** O HW20PRO mostra só cinco caracteres do nome de um contacto, e o hub corta-o aí. */
final class WonlexContactNameTest extends TestCase
{
    public function testAWonlexContactNameIsCutToFiveCharacters(): void
    {
        $capability = new PhonebookCapability();

        $contacts = $capability->sanitizeInput('wonlex-json', [['name' => 'Enfermeira', 'phone' => '+351210000000']]);

        self::assertSame('Enfer', $contacts[0]['name'] ?? null);
        self::assertSame(5, $capability->meta('wonlex-json')['name']['maxLength']);
    }
}
