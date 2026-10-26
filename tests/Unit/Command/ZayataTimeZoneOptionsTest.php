<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/** O fuso escolhe-se pelas zonas da IANA, que mudam de hora sozinhas, e não por desvios fixos. */
final class PillDispenserTimeZoneOptionsTest extends TestCase
{
    /** O desvio de agora escreve-o a dashboard, e por isso a lista não envelhece com a mudança de hora. */
    public function testTheOptionsAreTheIanaZonesAlone(): void
    {
        $options = DeviceConfigurationCatalog::configForProtocol('zayata-m228', 'time_zone')['options'] ?? [];

        self::assertSame(\DateTimeZone::listIdentifiers(), array_column($options['timeZone'] ?? [], 'value'));
        self::assertSame([['value' => 'Africa/Abidjan']], array_slice($options['timeZone'], 0, 1));
        self::assertSame('Europe/Lisbon', $options['default'] ?? null);
    }
}
