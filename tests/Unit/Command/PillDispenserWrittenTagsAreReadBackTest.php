<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Uma definição que se escreve e nunca se lê mostra para sempre a intenção em vez do que o
 * aparelho ficou a ter. O `0x05` tem de pedir todas as TAGs que o `0x06` sabe escrever.
 */
final class PillDispenserWrittenTagsAreReadBackTest extends TestCase
{
    public function testEveryConfigurationTagTheHubWritesIsAlsoReadBack(): void
    {
        $adapter = new PillDispenserAdapter();
        $missing = [];
        $seen = 0;

        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            $command = (string)$entry['command'];
            $frame = DeviceCommandCatalog::buildDownlink('zayata-m228', '869243062262262', $command, self::sample());
            $decoded = $adapter->decodeIncoming($frame);
            self::assertIsArray($decoded, $command);

            if (($decoded['packetType'] ?? 0) !== 0x06) {
                continue;
            }

            $seen++;
            foreach (array_keys($decoded['tlv'] ?? []) as $tag) {
                if (!in_array($tag, PillDispenserAdapter::CONFIGURATION_TAGS, true)) {
                    $missing[] = sprintf('%s escreve 0x%04X e o 0x05 nunca a pede', $command, $tag);
                }
            }
        }

        self::assertSame([], $missing);
        // Sem amostra o varrimento fica verde a medir nada.
        self::assertGreaterThan(5, $seen);
    }

    /** @return array<string, mixed> */
    private static function sample(): array
    {
        return [
            'cell' => 1,
            'minutes' => 1,
            'cells' => 1,
            'enabled' => false,
            'volume' => 0,
            'ringtone' => 0,
            'language' => 0,
            'timeZone' => 0,
            'startDate' => '2026-01-01',
            'endDate' => '2026-01-02',
            'startHour' => 22,
            'startMinute' => 0,
            'endHour' => 7,
            'endMinute' => 0,
            'plans' => [],
        ];
    }
}
