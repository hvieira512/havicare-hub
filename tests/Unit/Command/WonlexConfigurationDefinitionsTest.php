<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use PHPUnit\Framework\TestCase;

final class WonlexConfigurationDefinitionsTest extends TestCase
{
    public function testIncomingCallRestrictionIsAPlainToggle(): void
    {
        $entry = $this->entry('wonlexCallInLimitSwitch');

        self::assertSame('toggle', $entry['input']);
        self::assertSame(['switchState'], $entry['fields']);
    }

    /** Os comandos continuam a sair pelas definições guiadas que os partilham. */
    public function testTheRawJsonEnvelopesAreNotInTheCatalog(): void
    {
        self::assertNull(DeviceConfigurationCatalog::configForProtocol('wonlex-json', 'deviceConfig'));
        self::assertNull(DeviceConfigurationCatalog::configForProtocol('wonlex-json', 'deviceMeasuringFrequency'));
    }

    /** A spec dá 38,5 °C de exemplo aos dois alertas de temperatura. */
    public function testTemperatureAlertsStartAtThirtyEightAndAHalf(): void
    {
        foreach (['wonlexTemperatureExceedRemind', 'wonlexTemperatureBelowRemind'] as $key) {
            self::assertSame(
                ['switchState' => true, 'RemindValue' => 38.5],
                ConfigurationInputDefaults::forEntry($this->entry($key)),
                $key,
            );
        }

        self::assertSame(
            ['switchState' => true, 'reminderValue' => 90],
            ConfigurationInputDefaults::forEntry($this->entry('wonlexBloodOxygenWarn')),
        );
    }

    /** A spec só dá de exemplo o 120 da alta, e um alerta baixo a 120 dispara sempre. */
    public function testLowHeartRateAlertHasNoDefaultLimit(): void
    {
        $low = ConfigurationInputDefaults::forEntry($this->entry('wonlexHeartRateLowRemind'));
        $high = ConfigurationInputDefaults::forEntry($this->entry('wonlexHeartRateHighRemind'));

        self::assertArrayNotHasKey('remindValue', $low);
        self::assertSame(120, $high['remindValue'] ?? null);
    }

    /** @return array<string, mixed> */
    private function entry(string $key): array
    {
        $entry = DeviceConfigurationCatalog::configForProtocol('wonlex-json', $key);
        self::assertIsArray($entry, $key);

        return $entry;
    }
}
