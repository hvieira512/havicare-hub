<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

/**
 * Deitada, a régua de um modal soma mais do que a calha de um telemóvel. O que passa dela
 * tem de continuar alcançável -- a contagem de cada separador não aparece em mais lado
 * nenhum --, e há duas maneiras de o garantir: quebrar para a linha de baixo, ou deslizar.
 * A que não faz nenhuma das duas corta e esconde.
 */
final class ModalSideNavReachTest extends TestCase
{
    public function testNoSideNavCutsTabsOffScreen(): void
    {
        preg_match_all('/class="([^"]*\bmodal-side-nav\b[^"]*)"/', $this->page(), $matches);

        $this->assertNotEmpty($matches[1], 'Não se encontrou menu lateral nenhum -- a varredura falhou.');

        foreach ($matches[1] as $classes) {
            if (preg_match('/\bflex-wrap\b/', $classes) === 1) {
                continue;
            }
            $this->assertTrue(
                $this->slidesOnNarrowScreens(),
                "Um menu lateral que não quebra e que a folha de estilo não põe a deslizar:\n  {$classes}",
            );
        }
    }

    /** A tira que não quebra desliza, e a barra some-se para a meia pastilha se ler como tal. */
    private function slidesOnNarrowScreens(): bool
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Dashboard/main.css');
        if (preg_match('/\.modal-side-nav\s*\{[^}]*\}/', $css, $rule) !== 1) {
            return false;
        }

        return str_contains($rule[0], 'overflow-x: auto');
    }

    private function page(): string
    {
        $dashboardApiAuthRequired = true;
        $assetVersion = '';
        $downlinkQueueTtlSeconds = 300;

        ob_start();
        require dirname(__DIR__, 3) . '/src/Dashboard/index.php';

        return (string) ob_get_clean();
    }
}
