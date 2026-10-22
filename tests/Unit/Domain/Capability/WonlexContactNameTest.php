<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Domain\Capability\Contacts\PhonebookCapability;
use PHPUnit\Framework\TestCase;

/** A especificação da Wonlex não limita o nome do contacto, e o hub não o corta. */
final class WonlexContactNameTest extends TestCase
{
    public function testAWonlexContactKeepsItsWholeName(): void
    {
        $capability = new PhonebookCapability();

        $contacts = $capability->sanitizeInput('wonlex-json', [['name' => 'Enfermeira', 'phone' => '+351210000000']]);

        self::assertSame('Enfermeira', $contacts[0]['name'] ?? null);
        self::assertNull($capability->meta('wonlex-json')['name']['maxLength']);
    }
}
