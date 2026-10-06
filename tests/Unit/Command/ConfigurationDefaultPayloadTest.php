<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * O payload por omissão é o ponto de partida do formulário de uma capacidade nunca
 * configurada, e o construtor do protocolo tem de o aceitar.
 */
final class ConfigurationDefaultPayloadTest extends TestCase
{
    /** Campos que o utilizador tem de preencher: o vazio por omissão não se espera enviável. */
    private const INPUTS_AWAITING_USER_INPUT = [
        'call_whitelist',
        'makeCall',
        'phone',
        'phonebook',
        'pushMessage',
        'sos_contacts',
        'takePills',
        'text',
    ];

    /**
     * O `uploadInterval` do four-p-touch tem 0 por omissão e o construtor exige-o positivo:
     * gravar o formulário intocado falha alto em vez de enviar coisa errada.
     */
    private const KNOWN_UNSENDABLE_DEFAULTS = [
        'four-p-touch.uploadInterval',
        // O limite do alerta baixo da Wonlex fica vazio de propósito: quem o liga tem de o escolher.
        'wonlex-json.wonlexHeartRateLowRemind',
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
