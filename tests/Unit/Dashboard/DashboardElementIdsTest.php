<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

/**
 * Prova que cada `els.qualquerCoisa` que o JavaScript lê existe mesmo na página.
 *
 * O `cacheElements()` do `dom.js` recolhe todos os elementos com `id` uma vez, no
 * `DOMContentLoaded`, e devolve-os num objecto simples. Um `id` que não exista dá
 * `undefined` -- não dá erro. E como quase todos os sítios que lêem daqui se protegem com
 * `?.` ou com um `if`, renomear um `id` num template PHP não parte nada de forma visível:
 * o ouvinte deixa de ser ligado e o botão passa a não fazer nada, em silêncio, até alguém
 * reparar.
 *
 * O acoplamento entre os `id` do PHP e o JavaScript é total e não vai deixar de o ser --
 * é o preço de não haver passo de compilação, e o passo de compilação custa mais. O que
 * este teste faz é tornar o acoplamento verificável: desenha a página a sério, com os
 * modais e com os auxiliares (`pagination_component`, `search_input`, ...) já executados,
 * e compara os `id` que dela saem com os nomes que o JavaScript procura.
 *
 * Uma falha aqui é uma de duas coisas: um `id` renomeado só de um lado, ou um `els.x` que
 * ficou para trás quando o elemento saiu da página.
 */
final class DashboardElementIdsTest extends TestCase
{
    private static ?string $renderedPage = null;

    public function testEveryElementIdReadByJavaScriptExistsInTheRenderedPage(): void
    {
        $rendered = $this->renderedIds();
        $referenced = $this->referencedIds();

        $this->assertNotEmpty($rendered, 'A página não produziu `id` nenhum -- o desenho falhou.');
        $this->assertNotEmpty($referenced, 'Não se encontrou nenhum `els.x` -- a varredura falhou.');

        $missing = array_values(array_diff(array_keys($referenced), $rendered));
        sort($missing);

        $this->assertSame([], $missing, $this->explain($missing, $referenced));
    }

    /**
     * Os `id` que a página produz, com os auxiliares já corridos.
     *
     * @return list<string>
     */
    private function renderedIds(): array
    {
        preg_match_all('/\bid="([A-Za-z][A-Za-z0-9_-]*)"/', $this->page(), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Os nomes que o JavaScript lê do objecto dos elementos, e onde os lê.
     *
     * Só apanha o acesso literal (`els.nome`): o acesso dinâmico (`els[chave]`) não se
     * consegue resolver sem correr o código, e não há nenhum neste momento.
     *
     * @return array<string, list<string>> nome do elemento => ficheiros que o lêem
     */
    private function referencedIds(): array
    {
        $root = dirname(__DIR__, 3) . '/src/Dashboard';
        $files = [$root . '/main.js', ...$this->javaScriptFilesIn($root . '/dashboard')];

        $referenced = [];
        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            preg_match_all('/\bels\??\.([A-Za-z][A-Za-z0-9_]*)/', $source, $matches);
            foreach ($matches[1] as $name) {
                $relative = substr($file, strlen($root) + 1);
                $referenced[$name][$relative] = true;
            }
        }

        return array_map(static fn (array $files): array => array_keys($files), $referenced);
    }

    /** @return list<string> */
    private function javaScriptFilesIn(string $directory): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * A página inteira, desenhada uma vez por processo.
     *
     * O `index.php` espera o `$dashboardApiAuthRequired` de quem o inclui, tal como o
     * `DashboardHttpServer::page()` lho dá.
     */
    private function page(): string
    {
        if (self::$renderedPage !== null) {
            return self::$renderedPage;
        }

        $dashboardApiAuthRequired = true;
        ob_start();
        require dirname(__DIR__, 3) . '/src/Dashboard/index.php';

        return self::$renderedPage = (string)ob_get_clean();
    }

    /**
     * @param list<string> $missing
     * @param array<string, list<string>> $referenced
     */
    private function explain(array $missing, array $referenced): string
    {
        if ($missing === []) {
            return '';
        }

        $lines = ['O JavaScript lê elementos que a página não produz:'];
        foreach ($missing as $name) {
            $lines[] = sprintf('  els.%s  <- %s', $name, implode(', ', $referenced[$name] ?? []));
        }

        return implode("\n", $lines);
    }
}
