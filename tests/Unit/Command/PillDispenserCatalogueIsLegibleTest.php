<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Metade dos nomes («Repor o prato», «Parâmetros de controlo») não se explica a si própria; a
 * categoria é uma só, a da definição, que manda no modal.
 */
final class PillDispenserCatalogueIsLegibleTest extends TestCase
{
    public function testEverySettingExplainsWhatItDoes(): void
    {
        $unexplained = [];
        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            if (trim((string)($entry['help'] ?? '')) === '') {
                $unexplained[] = (string)$entry['label'];
            }
        }

        self::assertSame([], $unexplained);
    }

    /** A frase tem de dizer mais do que a etiqueta: três palavras a repeti-la não são ajuda. */
    public function testTheExplanationIsAnActualSentence(): void
    {
        $tooShort = [];
        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            $help = trim((string)($entry['help'] ?? ''));
            if (mb_strlen($help) < 40) {
                $tooShort[] = sprintf('%s: «%s»', $entry['label'], $help);
            }
        }

        self::assertSame([], $tooShort);
    }

    /** O catálogo de capacidades e as definições têm de concordar sobre onde cada coisa vive. */
    public function testTheCategoryIsTheSameOnBothSides(): void
    {
        // Uma telemetria que também se pede vive nos dois sítios de propósito: o cartão onde se
        // lê, e o botão onde se carrega (o `device_status` em Sistema).
        $capabilitySection = [];
        foreach (CapabilityCatalog::definitionsForDeviceType('pill_dispenser') as $definition) {
            if (($definition['isTelemetry'] ?? false) === true) {
                continue;
            }
            $capabilitySection[(string)$definition['key']] = (string)$definition['section'];
        }

        // As definições usam os nomes curtos das secções; as capacidades usam os do ecrã.
        $equivalent = [
            'health' => 'health',
            'alerts' => 'alarms',
            'system' => 'settings_system',
        ];

        $disagreements = [];
        foreach (DeviceConfigurationCatalog::configsForProtocol('zayata-m228') as $entry) {
            $key = (string)$entry['key'];
            $expected = $equivalent[(string)$entry['category']] ?? null;
            $declared = $capabilitySection[$key] ?? null;
            if ($expected !== null && $declared !== null && $expected !== $declared) {
                $disagreements[] = sprintf('%s: definição diz %s, capacidade diz %s', $key, $expected, $declared);
            }
        }

        self::assertSame([], $disagreements);
    }
}
