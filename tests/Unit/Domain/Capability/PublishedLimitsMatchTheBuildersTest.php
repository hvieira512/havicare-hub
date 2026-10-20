<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\ConfigurationInputDefaults;
use PHPUnit\Framework\TestCase;

/** O que o catálogo publica como limite é o que o construtor aceita, nas duas pontas. */
final class PublishedLimitsMatchTheBuildersTest extends TestCase
{
    public function testEveryPublishedLimitIsTheOneTheBuilderEnforces(): void
    {
        $checked = 0;
        foreach (['four-p-touch', 'wonlex-json', 'vivistar-iw'] as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $entry) {
                $key = (string)$entry['key'];
                // Os outros campos limitados no mínimo, para a recusa só poder vir do campo em teste.
                $payload = array_map(static fn(array $bounds): int => $bounds['min'], $entry['bounds'] ?? [])
                    + ConfigurationInputDefaults::forEntry($entry);
                foreach ($entry['bounds'] ?? [] as $field => $bounds) {
                    $at = static fn(int $value): ?string => DeviceConfigurationCatalog::validate($protocol, $key, [$field => $value] + $payload);
                    $label = "{$protocol} {$key}.{$field}";

                    self::assertNull($at($bounds['min']), "{$label} recusa o mínimo publicado");
                    self::assertNotNull($at($bounds['min'] - 1), "{$label} aceita abaixo do mínimo");
                    if (isset($bounds['max'])) {
                        self::assertNull($at($bounds['max']), "{$label} recusa o máximo publicado");
                        self::assertNotNull($at($bounds['max'] + 1), "{$label} aceita acima do máximo");
                    }
                    $checked++;
                }
            }
        }

        self::assertGreaterThanOrEqual(20, $checked);
    }

    /** O catálogo da Wonlex fala com os nomes que a API devolve no valor. */
    public function testTheWonlexCatalogUsesThePublicFieldNames(): void
    {
        foreach (DeviceConfigurationCatalog::configsForProtocol('wonlex-json') as $entry) {
            self::assertNotContains('switchState', $entry['fields'], (string)$entry['key']);
            self::assertNotContains('exerciseSwitchState', $entry['fields'], (string)$entry['key']);
        }
    }
}
