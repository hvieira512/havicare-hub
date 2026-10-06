<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

/**
 * Abaixo do `xl` as leituras e os pedidos são separadores, senão no telemóvel a lista inteira
 * das leituras fica antes do primeiro pedido.
 */
final class ActivityTabsTest extends TestCase
{
    private const PANES = ['telemetryColumn', 'downlinkColumn'];

    public function testTheTabsOnlyExistBelowExtraLarge(): void
    {
        preg_match(
            '/<div class="([^"]*)"[^>]*id="activityTabs"/',
            $this->page(),
            $match,
        );

        $this->assertNotEmpty($match, 'Não há régua de separadores da atividade.');
        $this->assertStringContainsString(
            'd-xl-none',
            $match[1],
            "A régua tem de sair quando as duas colunas cabem lado a lado:\n  {$match[1]}",
        );
    }

    public function testEachPanelIsAPane(): void
    {
        foreach (self::PANES as $pane) {
            preg_match('/<div id="' . $pane . '" class="([^"]*)"/', $this->page(), $match);

            $this->assertNotEmpty($match, "Não se encontrou o painel {$pane}.");
            $this->assertStringContainsString(
                'tab-pane',
                $match[1],
                "O painel {$pane} tem de ser um separador:\n  {$match[1]}",
            );
        }
    }

    public function testEachTabPointsAtItsPane(): void
    {
        foreach (self::PANES as $pane) {
            $this->assertMatchesRegularExpression(
                '/data-bs-target="#' . $pane . '"[^>]*role="tab"/',
                $this->page(),
                "Nenhum separador aponta para o painel {$pane}.",
            );
        }
    }

    public function testEachTabCarriesItsOwnCount(): void
    {
        foreach (['telemetryTabCount', 'downlinkTabCount'] as $id) {
            $this->assertStringContainsString(
                'id="' . $id . '"',
                $this->page(),
                "O separador precisa da contagem {$id}: no telemóvel o cabeçalho da coluna não se vê.",
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
