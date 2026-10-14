<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/** O fuso escolhe-se pelas zonas da IANA, que mudam de hora sozinhas, e não por desvios fixos. */
final class PillDispenserTimeZoneOptionsTest extends TestCase
{
    public function testTheOptionsAreTheIanaZonesWithTheirOffsetNow(): void
    {
        $options = DeviceConfigurationCatalog::configForProtocol('zayata-m228', 'time_zone')['options'] ?? [];
        $byValue = array_column($options['timeZone'] ?? [], 'label', 'value');

        self::assertCount(count(\DateTimeZone::listIdentifiers()), $byValue);
        self::assertMatchesRegularExpression('/^Europe\/Lisbon — UTC[+−]0[01]:00$/u', $byValue['Europe/Lisbon'] ?? '');
        self::assertSame('Europe/Lisbon', $options['default'] ?? null);
    }
}
