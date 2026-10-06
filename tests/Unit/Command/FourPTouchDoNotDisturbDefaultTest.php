<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use PHPUnit\Framework\TestCase;

final class FourPTouchDoNotDisturbDefaultTest extends TestCase
{
    /** Um horário de silêncio pré-preenchido recusava chamadas a quem nunca o pediu. */
    public function testNeverSavedDoNotDisturbHasNoRanges(): void
    {
        $entry = DeviceConfigurationCatalog::configForProtocol('four-p-touch', 'doNotDisturb');
        self::assertIsArray($entry);

        self::assertSame(['ranges' => []], ConfigurationInputDefaults::forEntry($entry));
    }
}
