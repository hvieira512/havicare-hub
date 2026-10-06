<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Cada capacidade de telemetria tem ícone e tom no `CARD_STYLE`, senão o cartão sai cinzento
 * sem erro. Vive em PHP porque o catálogo é a fonte.
 */
final class TelemetryCardStyleTest extends TestCase
{
    private const CATALOG = __DIR__ . '/../../../src/Dashboard/dashboard/components/cards/telemetry.js';

    public function testEveryTelemetryCapabilityHasAnIconAndATone(): void
    {
        $styled = $this->styledKeys();
        $this->assertNotEmpty($styled, 'Não se encontrou o `CARD_STYLE` -- a varredura falhou.');

        $missing = array_values(array_diff($this->telemetryCapabilities(), $styled));
        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "Capacidades de telemetria sem entrada no `CARD_STYLE` do telemetry.js:\n  %s\n"
                . 'Sem ela o cartão sai com o ícone genérico e sem tom.',
            implode("\n  ", $missing),
        ));
    }

    /** @return list<string> */
    private function styledKeys(): array
    {
        $source = (string) file_get_contents(self::CATALOG);
        if (preg_match('/const CARD_STYLE = \{(.*?)\n\};/s', $source, $block) !== 1) {
            return [];
        }

        preg_match_all('/^\s{4}"?([a-z][a-z0-9_.]*)"?:\s*\[/m', $block[1], $matches);

        return $matches[1];
    }

    /** @return list<string> */
    private function telemetryCapabilities(): array
    {
        $keys = [];
        foreach (CapabilityCatalog::definitions() as $definition) {
            if (($definition['isTelemetry'] ?? false) === true) {
                $keys[(string) $definition['key']] = true;
            }
        }

        return array_keys($keys);
    }
}
