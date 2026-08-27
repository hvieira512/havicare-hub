<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Uma capacidade que o dispositivo nunca configurou é servida com um payload por omissão, e
 * a dashboard oferece-o como ponto de partida do formulário. Se o construtor de payloads do
 * protocolo o rejeitar, a capacidade não se consegue gravar num dispositivo novo.
 */
final class ConfigurationDefaultPayloadTest extends TestCase
{
    /**
     * Campos cujo valor por omissão é deliberadamente um vazio que o utilizador tem de
     * preencher: um número, uma mensagem, um nome. Não se espera que sejam enviáveis como
     * estão.
     */
    private const INPUTS_AWAITING_USER_INPUT = [
        'contacts',
        'list',
        'makeCall',
        'phone',
        'pushMessage',
        'takePills',
        'text',
    ];

    /**
     * O `uploadInterval` do four-p-touch tem 0 por omissão e o construtor dele exige um
     * intervalo positivo, por isso gravar o formulário intocado dá erro. Falha alto em vez de
     * enviar coisa errada, e por isso fica registado aqui em vez de corrigido às cegas.
     */
    private const KNOWN_UNSENDABLE_DEFAULTS = [
        'four-p-touch.uploadInterval',
    ];

    public function testEveryDefaultPayloadIsAcceptedByItsProtocolPayloadBuilder(): void
    {
        $rejected = [];

        foreach (ProtocolRegistry::protocolsWithConfigCatalog() as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                $key = (string)($entry['key'] ?? '');
                $input = (string)($entry['input'] ?? 'json');
                if ($key === '' || in_array($input, self::INPUTS_AWAITING_USER_INPUT, true)) {
                    continue;
                }
                if (in_array("{$protocol}.{$key}", self::KNOWN_UNSENDABLE_DEFAULTS, true)) {
                    continue;
                }

                $payload = ConfigurationInputDefaults::forEntry($entry);
                if ($payload === []) {
                    continue;
                }

                try {
                    DeviceConfigurationCatalog::commandPayload($protocol, $key, $payload);
                } catch (\Throwable $e) {
                    $rejected[] = "{$protocol}.{$key} ({$input}): {$e->getMessage()}";
                }
            }
        }

        self::assertSame([], $rejected, 'default payloads their own protocol cannot send');
    }

    public function testTheWonlexBloodPressureAlertDefaultCarriesBothThresholds(): void
    {
        // O valor por omissão leva os dois limiares que o construtor precisa, e não um
        // `reminderValue` só.
        $entry = $this->entry('wonlex-json', 'wonlexBPEarlyWarning');

        self::assertSame(
            ['switchState' => true, 'hpWarn' => 135, 'LPWarn' => 90],
            ConfigurationInputDefaults::forEntry($entry),
        );
    }

    public function testTheWonlexBloodPressureAlertAcceptsTheDashboardsEnabledFlag(): void
    {
        // A dashboard lê os interruptores como `enabled` em todas as capacidades, e esta não
        // é excepção.
        $payload = DeviceConfigurationCatalog::commandPayload('wonlex-json', 'wonlexBPEarlyWarning', [
            'enabled' => true,
            'hpWarn' => 140,
            'LPWarn' => 95,
        ]);

        self::assertSame([
            'configs' => [
                'BPEarlyWarning' => [
                    'switchState' => 1,
                    'hpWarn' => 140,
                    'LPWarn' => 95,
                ],
            ],
        ], $payload['payload']);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $protocol, string $key): array
    {
        $entry = DeviceConfigurationCatalog::configForProtocol($protocol, $key);
        self::assertIsArray($entry, "{$protocol} has no {$key} configuration entry");

        return $entry;
    }
}
