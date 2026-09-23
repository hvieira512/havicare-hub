<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Hub\Command\DeviceConfigurationCatalog;
use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Quem abre a dashboard sem conhecer o aparelho tem de conseguir administrá-lo.
 *
 * Metade dos nomes deste catálogo não se explica a si própria — «Repor o prato», «Parâmetros
 * de controlo», «Sincronizar configuração». Sem uma frase por baixo, quem opera fica a
 * adivinhar o que vai acontecer ao carregar no botão, e a adivinhar sobre a medicação de
 * alguém.
 *
 * E a categoria tem de ser uma só: o catálogo de capacidades e as definições declaram-na cada
 * um por seu lado, e discordavam no «Dispensar agora» — a capacidade dizia saúde, a definição
 * dizia sistema, e é a definição que manda no modal.
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

    /**
     * A frase tem de dizer alguma coisa.
     *
     * Um texto de três palavras a repetir a etiqueta não é ajuda nenhuma, e passava neste
     * teste se ele só verificasse que existe.
     */
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
        // Uma capacidade de telemetria que também se pede vive nos dois sítios de propósito: o
        // cartão fica onde a leitura se mostra, e o botão onde se carrega. O `device_status` é
        // isso — telemetria com uma acção «Atualizar estado» em Sistema.
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
