<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

/**
 * Quem esconde uma pastilha de contagem vazia é o `.count-chip:empty` do `shell.css`, e mais
 * ninguém. Um `d-none` na marcação não tem quem o tire e deixa a contagem invisível para
 * sempre.
 */
final class CountChipVisibilityTest extends TestCase
{
    public function testNoCountChipIsBornHidden(): void
    {
        preg_match_all('/class="([^"]*\bcount-chip\b[^"]*)"/', $this->page(), $matches);

        $this->assertNotEmpty($matches[1], 'Não se encontrou pastilha nenhuma -- a varredura falhou.');

        $hidden = array_values(array_filter(
            $matches[1],
            static fn (string $classes): bool => str_contains($classes, 'd-none'),
        ));

        $this->assertSame([], $hidden, sprintf(
            "Pastilhas de contagem a nascer com `d-none`:\n  %s",
            implode("\n  ", $hidden),
        ));
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
