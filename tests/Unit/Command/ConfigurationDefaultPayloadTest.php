<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * A capability the device has never configured is served with a default
 * payload, and the dashboard offers that payload as the starting point of the
 * form. If the protocol's payload builder rejects it, the capability cannot be
 * saved from a fresh device at all.
 */
final class ConfigurationDefaultPayloadTest extends TestCase
{
    /**
     * Inputs whose default is deliberately a blank the user has to fill in: a
     * phone number, a message, a name. Their defaults are not meant to be
     * sendable as they stand.
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
     * four-p-touch uploadInterval defaults to 0 while its builder demands a
     * positive interval, so saving the untouched form reports an error. It
     * fails loudly rather than sending something wrong, so it is recorded here
     * rather than fixed blind.
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
        // Regression: the default used to carry a single reminderValue, so the
        // builder rejected it for the two thresholds it actually needs.
        $entry = $this->entry('wonlex-json', 'wonlexBPEarlyWarning');

        self::assertSame(
            ['switchState' => true, 'hpWarn' => 135, 'LPWarn' => 90],
            ConfigurationInputDefaults::forEntry($entry),
        );
    }

    public function testTheWonlexBloodPressureAlertAcceptsTheDashboardsEnabledFlag(): void
    {
        // The dashboard reads switches back as 'enabled' for every capability;
        // this one used to be the exception that could not be saved.
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
