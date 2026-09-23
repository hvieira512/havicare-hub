<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use PHPUnit\Framework\TestCase;

/**
 * O que o utilizador escreveu no ecrã chega inteiro ao construtor da trama.
 *
 * Entre a configuração guardada e os bytes há um validador por protocolo, e o do dispensador
 * devolvia `[]` para tudo o que não conhecesse pelo nome. Três definições novas -- os dois
 * tempos da toma e a contagem de compartimentos carregados -- caíam nesse `default`: o
 * `desired_payload` na base dizia `{"minutes":20}`, o comando saía com payload vazio, e o
 * aparelho recebia zero e respondia «ACEITE». Nada falhava em lado nenhum, e a definição
 * simplesmente não valia.
 *
 * Os testes que já existiam entravam pelo `DeviceCommandCatalog`, que é o degrau a seguir, e
 * por isso não viam nada disto. Este entra pelo degrau de cima, que é por onde a dashboard
 * entra.
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
     * O construtor da trama já coloca cada plano no slot que ele pede, mas o validador
     * reconstruía cada plano com três campos e deitava o `slot` fora. O plano voltava a valer
     * por posição, que é exactamente o defeito que o slot existe para corrigir.
     */
    public function testTheAlarmSlotSurvivesTheValidator(): void
    {
        $built = DeviceConfigurationCatalog::commandPayloads('zayata-m228', 'medication_reminders', [
            'plans' => [['slot' => 5, 'hour' => 10, 'minute' => 24, 'enabled' => true]],
        ]);

        self::assertSame(5, $built[0]['payload']['plans'][0]['slot'] ?? null);
    }

    /**
     * A guarda contra a próxima definição esquecida.
     *
     * Uma entrada que declara campos tem de sair do validador com esses campos, nem que seja
     * com os valores de fábrica. Sair vazia é o defeito que passou despercebido: não dá erro,
     * não falha o envio, e o aparelho responde «ACEITE» ao zero que recebeu. Uma acção não
     * declara campos nenhuns e não entra nesta conta.
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
