<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O aparelho deixa cair em silêncio uma trama maior do que o `0x8003` declara, e a leitura da
 * configuração é a única que cresce com cada definição nova.
 */
final class PillDispenserReadFitsThePacketTest extends TestCase
{
    private const IMEI = '869243062262262';

    public function testEveryReadCommandFitsTheDeclaredPacketSize(): void
    {
        $oversized = [];

        foreach (self::readCommands() as $command) {
            $bytes = strlen(DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, $command, []));
            if ($bytes > PillDispenserAdapter::MAX_FRAME_BYTES) {
                $oversized[] = sprintf('%s produz %d bytes', $command, $bytes);
            }
        }

        self::assertSame([], $oversized);
    }

    /** Partir a leitura em duas e esquecer metade devolve configuração que nunca se confirma. */
    public function testTheReadCommandsCoverEveryConfigurationTagExactlyOnce(): void
    {
        $adapter = new PillDispenserAdapter();
        $seen = [];

        foreach (self::readCommands() as $command) {
            $decoded = $adapter->decodeIncoming(
                DeviceCommandCatalog::buildDownlink('zayata-m228', self::IMEI, $command, [])
            );
            self::assertIsArray($decoded, $command);
            foreach (array_keys($decoded['tlv'] ?? []) as $tag) {
                $seen[] = $tag;
            }
        }

        sort($seen);
        $expected = PillDispenserAdapter::CONFIGURATION_TAGS;
        sort($expected);

        self::assertSame($expected, $seen);
    }

    /** @return list<string> */
    private static function readCommands(): array
    {
        $commands = [];
        foreach (DeviceCommandCatalog::commandsForFeature('zayata-m228', 'sync_configuration') as $entry) {
            $commands[] = (string)$entry['command'];
        }

        self::assertNotSame([], $commands);

        return $commands;
    }
}
