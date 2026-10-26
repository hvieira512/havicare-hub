<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/** Uma definição que o validador não conheça sai com payload vazio e o aparelho aceita o zero, por isso entra-se pela dashboard. */
final class PillDispenserConfigurationPayloadTest extends TestCase
{
    /** Sem o `slot` vindo do validador, o construtor colocaria o plano por posição e perderia o alarme escolhido. */
    public function testTheAlarmSlotSurvivesTheValidator(): void
    {
        $built = DeviceConfigurationCatalog::commandPayloads('zayata-m228', 'medication_reminders', [
            'plans' => [['slot' => 5, 'hour' => 10, 'minute' => 24, 'enabled' => true]],
        ]);

        self::assertSame(5, $built[0]['payload']['plans'][0]['slot'] ?? null);
    }

    /** Uma entrada com campos sai sempre com eles, nem que de fábrica, porque vazia o aparelho aceita um zero sem erro. */
    public function testEverySettingWithFieldsComesBackWithThem(): void
    {
        $empty = [];
        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            $fields = $entry['fields'] ?? [];
            if ($fields === []) {
                continue;
            }

            $payload = DeviceConfigurationCatalog::commandPayloads(
                'zayata-m228',
                (string)$entry['key'],
                [],
            )[0]['payload'] ?? [];

            if (array_keys($payload) !== $fields) {
                $empty[] = (string)$entry['key'];
            }
        }

        self::assertSame([], $empty, 'o validador do dispensador deita fora o que estas definições configuram');
    }
}
