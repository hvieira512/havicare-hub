<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

/**
 * Abaixo do `lg` o menu lateral de um modal é uma linha, e tem de poder quebrar: com
 * `flex-nowrap` os separadores das definições somam mais do que a calha e o que passa fica
 * fora do ecrã -- a contagem de cada um inclusive, que não aparece em mais lado nenhum.
 */
final class ModalSideNavWrapTest extends TestCase
{
    public function testEverySideNavWrapsBelowLarge(): void
    {
        preg_match_all('/class="([^"]*\bmodal-side-nav\b[^"]*)"/', $this->page(), $matches);

        $this->assertNotEmpty($matches[1], 'Não se encontrou menu lateral nenhum -- a varredura falhou.');

        foreach ($matches[1] as $classes) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bflex-nowrap\b/',
                $classes,
                "Um menu lateral a cortar em vez de quebrar:\n  {$classes}",
            );
            $this->assertMatchesRegularExpression(
                '/\bflex-wrap\b/',
                $classes,
                "Um menu lateral sem quebra declarada:\n  {$classes}",
            );
        }
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
