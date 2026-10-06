<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Protocol\Adapter\PillDispenserAdapter;
use PHPUnit\Framework\TestCase;

/**
 * O hub só manda as doze TAGs de controlo que o `0x0C` do aparelho de ensaio devolveu: a
 * especificação descreve a série M2 inteira, e este firmware recusa o resto.
 */
final class PillDispenserControlTagsAreServedTest extends TestCase
{
    /**
     * A resposta ao `0x0C` do M228 de ensaio a 22 de setembro de 2026, escrita à mão: lida da tabela
     * do código não provava nada. Actualiza-se depois de perguntar ao firmware.
     *
     * @var list<int>
     */
    private const SERVED_TAGS = [
        0xA001, 0xA002, 0xA003, 0xA004,
        0xA011, 0xA021, 0xA022, 0xA023,
        0xA101, 0xA102, 0xA103, 0xA123,
    ];

    /** As cinco acções que saem como controlo, para o varrimento não poder ficar sem amostra. */
    private const CONTROL_COMMANDS = [
        'dispenseNow',
        'calibrateClock',
        'muteAlarm',
        'resetTray',
        'restartDevice',
    ];

    public function testEveryControlTagTheHubCanSendIsOneTheDeviceServes(): void
    {
        $adapter = new PillDispenserAdapter();
        $unserved = [];
        $seen = [];

        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            $command = (string)$entry['command'];
            $frame = DeviceCommandCatalog::buildDownlink('zayata-m228', '869243062262262', $command, self::sample());
            $decoded = $adapter->decodeIncoming($frame);
            self::assertIsArray($decoded, $command);

            if (($decoded['packetType'] ?? 0) !== 0x08) {
                continue;
            }

            $seen[] = $command;
            foreach (array_keys($decoded['tlv'] ?? []) as $tag) {
                if (!in_array($tag, self::SERVED_TAGS, true)) {
                    $unserved[] = sprintf('%s manda 0x%04X, que o aparelho não anuncia', $command, $tag);
                }
            }
        }

        self::assertSame([], $unserved);
        // Sem amostra o varrimento fica verde a medir nada.
        sort($seen);
        $expected = self::CONTROL_COMMANDS;
        sort($expected);
        self::assertSame($expected, $seen);
    }

    /**
     * Um corpo genérico que sirva a todos os comandos: o que aqui se mede é a família da TAG
     * que sai, e não o valor que ela leva.
     *
     * @return array<string, mixed>
     */
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
            'plans' => [],
        ];
    }
}
