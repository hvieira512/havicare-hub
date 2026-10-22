<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/** O payload por omissão é o ponto de partida do formulário de uma capacidade nunca configurada, e o protocolo tem de o aceitar. */
final class ConfigurationDefaultPayloadTest extends TestCase
{
    /** Campos que o utilizador tem de preencher: o vazio por omissão não se espera enviável. */
    private const INPUTS_AWAITING_USER_INPUT = [
        'call_whitelist',
        'makeCall',
        'phone',
        'phonebook',
        'pushMessage',
        'scaleUsers',
        'sos_contacts',
        'takePills',
        'voiceMonitor',
    ];

    /** O `uploadInterval` do four-p-touch é 0 por omissão e tem de ser positivo, e gravar sem mexer falha alto. */
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

    /** Um horário de silêncio pré-preenchido recusaria chamadas a quem nunca o pediu. */
    public function testNeverSavedDoNotDisturbHasNoRanges(): void
    {
        self::assertSame(['ranges' => []], ConfigurationInputDefaults::forEntry($this->entry('four-p-touch', 'doNotDisturb')));
    }

    public function testTheWonlexBloodPressureAlertDefaultCarriesBothThresholds(): void
    {
        self::assertSame(
            ['enabled' => true, 'systolicMmHg' => 135, 'diastolicMmHg' => 90],
            ConfigurationInputDefaults::forEntry($this->entry('wonlex-json', 'wonlexBPEarlyWarning')),
        );
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
