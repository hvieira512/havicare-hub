<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;

/**
 * Prova que cada `els.qualquerCoisa` que o JavaScript lê existe mesmo na página.
 *
 * O `cacheElements()` devolve `undefined` para um `id` que não exista, e a maior parte dos
 * leitores não se protege: renomear um `id` num template rebenta o arranque da dashboard e
 * devolve o ecrã de entrada. Onde há `?.`, o botão fica calado em vez de rebentar -- o que é
 * pior, porque ninguém dá por ele.
 *
 * Uma falha aqui é um `id` renomeado só de um lado, ou um `els.x` que ficou para trás.
 */
final class DashboardElementIdsTest extends TestCase
{
    /**
     * Os `id` que o JavaScript compõe a partir de um prefixo, e quem os compõe. Quem
     * acrescentar um par de prefixos escreve-os aqui, senão ficam sem rede.
     *
     * Os dois `PagerSummary` ficam de fora: o `pagination_component(..., withSummary: false)`
     * não os desenha, e o `detail.js` já conta com isso.
     */
    private const COMPOSED_IDS = [
        'telemetryPagerControls' => 'devices/detail.js',
        'downlinkPagerControls' => 'devices/detail.js',
        'radarHeartRateValue' => 'devices/radar-vitals.js',
        'radarHeartRateMin' => 'devices/radar-vitals.js',
        'radarHeartRateMax' => 'devices/radar-vitals.js',
        'radarHeartRateAvg' => 'devices/radar-vitals.js',
        'radarHeartRateChart' => 'devices/radar-vitals.js',
        'radarBreathRateValue' => 'devices/radar-vitals.js',
        'radarBreathRateMin' => 'devices/radar-vitals.js',
        'radarBreathRateMax' => 'devices/radar-vitals.js',
        'radarBreathRateAvg' => 'devices/radar-vitals.js',
        'radarBreathRateChart' => 'devices/radar-vitals.js',
        'settingsModelsCount' => 'settings/shell.js',
        'settingsCompanyCount' => 'settings/shell.js',
        'settingsDenylistCount' => 'settings/shell.js',
        'settingsApiUsersCount' => 'settings/shell.js',
    ];

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
     * Os nomes que o JavaScript lê da página, e onde os lê: o `els.nome` e o
     * `getElementById("nome")`, com que o ecrã de entrada e os modais apanham os seus.
     *
     * Um `getElementById` com variável não se apanha, e é isso que se quer -- esses são `id`
     * que o próprio JavaScript acabou de desenhar. Os compostos estão em `COMPOSED_IDS`.
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
            preg_match_all('/\bels\??\.([A-Za-z][A-Za-z0-9_]*)/', $source, $direct);
            preg_match_all('/getElementById\(\s*"([A-Za-z][A-Za-z0-9_-]*)"/', $source, $byId);
            foreach ([...$direct[1], ...$byId[1]] as $name) {
                $relative = substr($file, strlen($root) + 1);
                $referenced[$name][$relative] = true;
            }
        }

        foreach (self::COMPOSED_IDS as $name => $where) {
            $referenced[$name][$where] = true;
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
