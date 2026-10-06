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
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function settingsWithAValue(): iterable
    {
        yield 'avisar de atraso' => ['retrieval_warning', ['minutes' => 20]];
        yield 'dar como falhada' => ['retrieval_timeout', ['minutes' => 90]];
        yield 'compartimentos carregados' => ['loaded_cells', ['cells' => 14]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('settingsWithAValue')]
    public function testTheValueSurvivesTheValidator(string $key, array $payload): void
    {
        $built = DeviceConfigurationCatalog::commandPayloads('zayata-m228', $key, $payload);

        self::assertCount(1, $built);
        self::assertSame($payload, $built[0]['payload']);
    }

    /** Uma gama fora do que o aparelho aceita recusa-se aqui, e não na trama. */
    public function testATimeBeyondADayIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeviceConfigurationCatalog::commandPayloads('zayata-m228', 'retrieval_warning', ['minutes' => 1441]);
    }

    public function testMoreCellsThanTheTrayHasIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeviceConfigurationCatalog::commandPayloads('zayata-m228', 'loaded_cells', ['cells' => 29]);
    }

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
