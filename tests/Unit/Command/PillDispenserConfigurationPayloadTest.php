<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Uma definição que o validador do protocolo não conheça sai com payload vazio e o aparelho aceita
 * o zero; por isso entra-se pelo degrau da dashboard.
 */
final class PillDispenserConfigurationPayloadTest extends TestCase
{
    /**
     * O alarme que o plano escolheu não se perde no caminho.
     *
     * Sem o `slot` vindo do validador, o construtor coloca o plano por posição.
     */
    public function testTheAlarmSlotSurvivesTheValidator(): void
    {
        $built = DeviceConfigurationCatalog::commandPayloads('zayata-m228', 'medication_reminders', [
            'plans' => [['slot' => 5, 'hour' => 10, 'minute' => 24, 'enabled' => true]],
        ]);

        self::assertSame(5, $built[0]['payload']['plans'][0]['slot'] ?? null);
    }

    /**
     * Uma entrada que declara campos sai do validador com eles, nem que seja com os valores de
     * fábrica: vazia, o aparelho aceita um zero sem erro. Uma acção não declara campos.
     */
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
