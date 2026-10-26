<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Command\DeviceCommandCatalog;
use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/** O dicionário da dashboard cobre tudo o que a API manda sem texto: chaves, enumerações e verbos. */
final class PortugueseDictionaryTest extends TestCase
{
    /** As listas de opções que a dashboard nomeia pelo `Intl` ou pela recorrência, e não pelo dicionário. */
    private const NAMED_ELSEWHERE = ['time_zone.timeZone', 'language_timezone.language', 'alarm_clock.days', 'alarm_clock.mode'];

    /** Os campos que nenhum controlo escreve à vista: interruptores sem rótulo, listas e partes internas. */
    private const UNLABELLED_FIELDS = [
        'enabled', 'items', 'plans', 'alarms', 'alarmClockList', 'masterEnabled', 'id', 'phone', 'reminderSettings', 'voiceData',
        'mode', 'level', 'cells', 'minutes', 'volume', 'ringtone', 'format', 'unit', 'timeZone', 'profile', 'startHour',
        'startMinute', 'endHour', 'endMinute', 'interval', 'minPerMinute', 'maxPerMinute',
    ];

    /** @var array<string, mixed> */
    private array $dictionary;

    protected function setUp(): void
    {
        $this->dictionary = json_decode(
            (string)file_get_contents(__DIR__ . '/../../../src/Dashboard/dashboard/core/pt-PT.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public function testEveryCapabilityAndSectionHasAName(): void
    {
        $missing = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            if (!isset($this->dictionary['capabilities'][$definition['key']])) {
                $missing[] = $definition['key'];
            }
        }
        foreach (CapabilityCatalog::sections() as $section) {
            if (!isset($this->dictionary['sections'][$section])) {
                $missing[] = "section {$section}";
            }
        }

        self::assertSame([], array_values(array_unique($missing)));
    }

    public function testEveryOptionVerbConfirmationAndFieldOfTheCatalogueHasItsText(): void
    {
        $missing = [];
        foreach (ProtocolRegistry::protocolsWithConfigCatalog() as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                $key = (string)CapabilityCatalog::mapConfigurationKey((string)$entry['key']);
                $text = array_replace_recursive(
                    $this->dictionary['configurations'][$key] ?? [],
                    $this->dictionary['protocols'][$protocol][$key] ?? [],
                );
                $where = "{$protocol} {$key}";

                foreach ($entry['options'] ?? [] as $field => $options) {
                    if (!is_array($options) || !array_is_list($options) || in_array("{$key}.{$field}", self::NAMED_ELSEWHERE, true)) {
                        continue;
                    }
                    foreach ($options as $option) {
                        if (!isset($text['options'][$field][(string)$option['value']])) {
                            $missing[] = "{$where} option {$field}={$option['value']}";
                        }
                    }
                }
                foreach (array_filter([$entry['verb'] ?? null, ...array_values($entry['actions'] ?? [])]) as $verb) {
                    if (!isset($this->dictionary['verbs'][$verb])) {
                        $missing[] = "{$where} verb {$verb}";
                    }
                }
                if (($entry['risk'] ?? '') === 'destructive' && trim((string)($text['confirm'] ?? '')) === '') {
                    $missing[] = "{$where} confirm";
                }
                foreach ($entry['fields'] ?? [] as $field) {
                    if (!in_array($field, self::UNLABELLED_FIELDS, true) && !isset($text['fields'][$field]) && !isset($this->dictionary['fields'][$field])) {
                        $missing[] = "{$where} field {$field}";
                    }
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testEveryCommandHasAName(): void
    {
        $missing = [];
        foreach (ProtocolRegistry::keys() as $protocol) {
            foreach (DeviceCommandCatalog::commandsForProtocol($protocol) as $command) {
                if (!isset($this->dictionary['commands'][$command['id']]) && !isset($this->dictionary['capabilities'][$command['feature']])) {
                    $missing[] = "{$protocol} {$command['id']}";
                }
            }
        }

        self::assertSame([], $missing);
    }

    /** Um tipo de campo que o registo da dashboard não conhece cai no editor de JSON em cru. */
    public function testEveryDeclaredInputHasARenderer(): void
    {
        $registry = '';
        foreach (glob(__DIR__ . '/../../../src/Dashboard/dashboard/devices/config/inputs/*.js') ?: [] as $file) {
            $registry .= (string)file_get_contents($file);
        }

        $orphans = [];
        foreach (ProtocolRegistry::protocolsWithConfigCatalog() as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                if (preg_match('/^    ' . preg_quote((string)$entry['input'], '/') . ': \{/m', $registry) !== 1) {
                    $orphans[] = "{$protocol} {$entry['key']}: {$entry['input']}";
                }
            }
        }

        self::assertSame([], $orphans);
    }

    /** Uma tradução de uma chave que já não existe não serve ninguém e esconde o nome novo. */
    public function testTheDictionaryNamesOnlyWhatTheCatalogueDeclares(): void
    {
        $keys = array_column(CapabilityCatalog::definitions(), 'key');

        self::assertSame([], array_values(array_diff(array_keys($this->dictionary['capabilities']), $keys)));
        self::assertSame([], array_values(array_diff(array_keys($this->dictionary['configurations']), $keys)));
        self::assertSame([], array_values(array_diff(array_keys($this->dictionary['protocols']), ProtocolRegistry::keys())));
    }
}
