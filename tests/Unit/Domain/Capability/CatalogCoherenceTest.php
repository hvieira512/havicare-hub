<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Capability;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use Hub\Domain\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * As regras que fazem do catálogo um contrato e não uma lista: a mesma chave diz a mesma
 * coisa em todos os aparelhos, e nada é anunciado sem alguém o servir.
 */
final class CatalogCoherenceTest extends TestCase
{
    /** @var list<class-string<\Hub\Domain\Capability\Definition\CapabilityDefinitions>> */
    private const DEFINITIONS = [
        \Hub\Domain\Capability\Definition\WatchCapabilityDefinitions::class,
        \Hub\Domain\Capability\Definition\NcsCapabilityDefinitions::class,
        \Hub\Domain\Capability\Definition\RadarCapabilityDefinitions::class,
        \Hub\Domain\Capability\Definition\GatewayCapabilityDefinitions::class,
        \Hub\Domain\Capability\Definition\DiaperSensorCapabilityDefinitions::class,
        \Hub\Domain\Capability\Definition\BraceletCapabilityDefinitions::class,
        \Hub\Domain\Capability\Definition\PillDispenserCapabilityDefinitions::class,
    ];

    public function testTheSameKeyCarriesTheSameLabelEverywhere(): void
    {
        foreach ($this->byKey('label') as $key => $labels) {
            self::assertCount(1, $labels, sprintf(
                'A chave %s tem %d etiquetas: %s',
                $key,
                count($labels),
                implode(' | ', array_map(
                    static fn (string $label, array $types): string => "\"{$label}\" (" . implode(', ', $types) . ')',
                    array_keys($labels),
                    $labels,
                )),
            ));
        }
    }

    public function testTheSameKeyLivesInTheSameSection(): void
    {
        foreach ($this->byKey('section') as $key => $sections) {
            self::assertCount(1, $sections, sprintf(
                'A chave %s aparece em %s',
                $key,
                implode(' e ', array_map(
                    static fn (string $section, array $types): string => "{$section} (" . implode(', ', $types) . ')',
                    array_keys($sections),
                    $sections,
                )),
            ));
        }
    }

    /**
     * O `isEventType` resolve pela chave e não pelo par aparelho/chave: uma chave que fosse
     * acontecimento num aparelho e leitura noutro saía no canal MQTT errado num dos dois.
     */
    public function testNoKeyIsAnEventHereAndTelemetryThere(): void
    {
        $events = [];
        $rest = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            $bucket = ($definition['isEvent'] ?? false) ? 'events' : 'rest';
            ${$bucket}[$definition['key']][] = $definition['deviceType'];
        }

        self::assertSame([], array_values(array_intersect(array_keys($events), array_keys($rest))));
    }

    /** Uma capacidade anunciada que nenhum protocolo serve é um botão que nunca responde. */
    public function testEveryConfigurableCapabilityIsServedBySomeProtocol(): void
    {
        $served = [];
        foreach (ProtocolRegistry::keys() as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $config) {
                $generic = CapabilityCatalog::mapConfigurationKey((string)($config['key'] ?? ''));
                if ($generic !== null) {
                    $served[$generic] = true;
                }
            }
        }

        $orphans = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            if ($definition['isConfigurable'] && !isset($served[$definition['key']])) {
                $orphans[] = $definition['deviceType'] . '/' . $definition['key'];
            }
        }

        self::assertSame([], $orphans);
    }

    /**
     * Um `setting` viaja num comando nativo e um `hubSetting` é aplicado pelo hub. Sem esta
     * guarda, uma configuração sem comando passava por definição normal e o painel desenhava
     * um campo que não mandava nada a lado nenhum.
     */
    public function testASettingTravelsInANativeCommandAndAHubSettingDoesNot(): void
    {
        $roles = [];
        foreach (self::DEFINITIONS as $class) {
            foreach ($class::roles() as $key => $role) {
                $roles[$key] = $role;
            }
        }

        $wrong = [];
        foreach (ProtocolRegistry::keys() as $protocol) {
            foreach (DeviceConfigurationCatalog::configsForProtocol($protocol) as $config) {
                $key = (string)($config['key'] ?? '');
                $role = $roles[$key] ?? null;
                if ($role !== 'setting' && $role !== 'hubSetting') {
                    continue;
                }

                $travels = trim((string)($config['command'] ?? '')) !== '';
                if ($travels !== ($role === 'setting')) {
                    $wrong[] = "{$protocol}/{$key}: papel {$role}, comando nativo "
                        . ($travels ? 'presente' : 'vazio');
                }
            }
        }

        self::assertSame([], $wrong);
    }

    /**
     * @return array<string, array<string, list<string>>> chave => valor => tipos que o usam
     */
    private function byKey(string $field): array
    {
        $grouped = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            $grouped[$definition['key']][(string)$definition[$field]][] = $definition['deviceType'];
        }

        return $grouped;
    }
}
