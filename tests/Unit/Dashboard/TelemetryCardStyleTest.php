<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Domain\Capability\CapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Prova que cada capacidade de telemetria do catálogo tem ícone e tom no `CARD_STYLE`.
 *
 * Sem entrada, o `cardIcon()` devolve o `fa-circle-info` genérico e o `cardTone()` devolve
 * vazio: o cartão aparece na mesma, cinzento e sem nada a dizer que falta escolher a cor. Uma
 * capacidade nova passava despercebida até alguém reparar no ecrã.
 *
 * O catálogo é a fonte, e por isso o teste vive em PHP: o frontend só o conhece em execução,
 * pela resposta do `/api/capabilities`.
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

    /**
     * As chaves do `CARD_STYLE`, lidas do bloco que vai da abertura até à primeira chaveta a
     * fechar na coluna zero.
     *
     * @return list<string>
     */
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
