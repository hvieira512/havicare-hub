<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Dashboard\DashboardHttpServer;
use PHPUnit\Framework\TestCase;

/**
 * Os módulos da dashboard entram pelo caminho e não por uma etiqueta de revalidação.
 *
 * A origem pede `no-cache`, mas quem está pelo meio pode não obedecer: a Cloudflare à frente
 * do hub reescreve o cabeçalho para `max-age=14400`, e o browser passa quatro horas a servir
 * ficheiros seus sem perguntar nada. Num refactor que mude ficheiros de sítio isso mistura
 * duas versões no mesmo arranque -- um módulo velho a importar um caminho que já não existe
 * rebenta o `import()` inteiro, e um módulo velho a desenhar um catálogo novo cai no editor
 * de JSON genérico porque não conhece o descritor que lhe chega.
 *
 * A impressão digital no caminho resolve-o sem depender de ninguém: o conjunto muda, os URL
 * mudam todos, e nenhuma cópia velha chega a ser pedida. O prefixo é herdado pelos `import`
 * relativos, que é o que faz o grafo inteiro acompanhar sem haver um passo de compilação.
 */
final class DashboardAssetVersionTest extends TestCase
{
    public function testThePageLoadsTheModuleGraphFromAVersionedPath(): void
    {
        $page = $this->renderPage('cafe1234cafe');

        self::assertStringContainsString(
            'src="/v/cafe1234cafe/main.js"',
            $page,
            'o ponto de entrada tem de trazer a versão, senão o grafo todo fica sem ela',
        );
        self::assertStringContainsString('href="/v/cafe1234cafe/main.css"', $page);
        self::assertStringContainsString('href="/v/cafe1234cafe/assets/css/base.css"', $page);
    }

    /**
     * O que já tem impressão digital própria não a leva outra vez: uma versão nossa nova
     * obrigava a puxar o Bootstrap e as fontes de novo sem eles terem mudado.
     */
    public function testThirdPartyAssetsKeepTheirOwnPaths(): void
    {
        $page = $this->renderPage('cafe1234cafe');

        self::assertStringContainsString('href="/assets/vendor/bootstrap/bootstrap.min.css"', $page);
        self::assertStringNotContainsString('/v/cafe1234cafe/assets/vendor/', $page);
    }

    /** O prefixo é um endereço, não um ficheiro: por baixo serve-se o mesmo que sem ele. */
    public function testAVersionedPathResolvesToTheSameFileAsThePlainOne(): void
    {
        self::assertSame(
            $this->resolve('/main.js'),
            $this->resolve('/v/cafe1234cafe/main.js'),
        );
        self::assertSame(
            $this->resolve('/dashboard/app.js'),
            $this->resolve('/v/cafe1234cafe/dashboard/app.js'),
        );
    }

    /** Com a versão no caminho, o ficheiro nunca muda debaixo do URL e pode ficar guardado. */
    public function testAVersionedAssetIsCachedForGood(): void
    {
        $response = $this->serve('/v/cafe1234cafe/dashboard/app.js');

        self::assertStringContainsString('immutable', $response->getHeaderLine('Cache-Control'));
    }

    /** Sem versão no caminho não há nada que garanta o conteúdo: revalida-se sempre. */
    public function testAnAssetWithoutAVersionKeepsRevalidating(): void
    {
        $response = $this->serve('/dashboard/app.js');

        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertNotSame('', $response->getHeaderLine('ETag'));
    }

    /**
     * É esta que prende o defeito: acrescentar, apagar ou alterar um módulo tem de mudar a
     * versão. Se não mudasse, o URL ficava igual com `immutable` por cima -- que é pior do
     * que o que se está a corrigir.
     */
    public function testTheVersionChangesWithEveryShapeOfChange(): void
    {
        $root = sys_get_temp_dir() . '/hub-asset-version-' . bin2hex(random_bytes(4));
        mkdir($root . '/nested', 0o777, true);
        file_put_contents($root . '/a.js', 'const a = 1;');
        file_put_contents($root . '/nested/b.js', 'const b = 2;');

        try {
            $original = $this->fingerprint($root);

            file_put_contents($root . '/nested/b.js', 'const b = 22222;');
            touch($root . '/nested/b.js', time() + 1);
            clearstatcache();
            self::assertNotSame($original, $this->fingerprint($root), 'alterar um módulo muda a versão');

            file_put_contents($root . '/nested/b.js', 'const b = 2;');
            touch($root . '/nested/b.js', time());
            clearstatcache();

            file_put_contents($root . '/nested/c.js', 'const c = 3;');
            clearstatcache();
            $withExtraFile = $this->fingerprint($root);
            self::assertNotSame($original, $withExtraFile, 'acrescentar um módulo muda a versão');

            unlink($root . '/nested/c.js');
            unlink($root . '/nested/b.js');
            clearstatcache();
            self::assertNotSame($original, $this->fingerprint($root), 'apagar um módulo muda a versão');
        } finally {
            foreach (glob($root . '/nested/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($root . '/*.js') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($root . '/nested');
            @rmdir($root);
        }
    }

    /**
     * O `immutable` é uma promessa sobre o endereço, não sobre o processo: o corpo guardado
     * em memória tem de acompanhar o ficheiro. No hub local cada gravação muda a versão, e o
     * endereço novo tinha de servir bytes novos -- servir os velhos era pior do que o defeito
     * que isto corrige, porque aí nem recarregar resolvia.
     */
    public function testAVersionedAssetStillServesTheBytesOnDisk(): void
    {
        $path = sys_get_temp_dir() . '/hub-asset-version-body-' . bin2hex(random_bytes(4)) . '.js';
        file_put_contents($path, 'const a = 1;');
        touch($path, time() - 10);
        clearstatcache(true, $path);

        $method = new \ReflectionMethod(DashboardHttpServer::class, 'staticFile');
        $server = $this->server();

        try {
            $first = $method->invoke($server, $path, new ServerRequest('GET', '/v/aaaaaa/dashboard/x.js'));
            self::assertSame('const a = 1;', (string)$first->getBody());

            file_put_contents($path, 'const a = 22;');
            touch($path, time());
            clearstatcache(true, $path);

            $second = $method->invoke($server, $path, new ServerRequest('GET', '/v/bbbbbb/dashboard/x.js'));
            self::assertSame(
                'const a = 22;',
                (string)$second->getBody(),
                'o corpo não pode ficar preso ao que estava em memória',
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * A página é quem nomeia a versão a carregar. Guardada, apontava para a anterior, e o
     * deploy não chegava a quem já tivesse estado lá.
     */
    public function testThePageItselfIsNeverKept(): void
    {
        $method = new \ReflectionMethod(DashboardHttpServer::class, 'html');
        /** @var \React\Http\Message\Response $response */
        $response = $method->invoke($this->server(), '<!doctype html>');

        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
    }

    /** A versão é um endereço público: não pode servir de caminho para fora da raiz. */
    public function testTheVersionPrefixIsNotAWayOutOfTheRoot(): void
    {
        self::assertNull($this->resolve('/v/cafe1234cafe/dashboard/../../index.php'));
        self::assertNull($this->resolve('/v/cafe1234cafe/../index.php'));
    }

    /** Um prefixo que não seja uma versão continua a não ser um caminho conhecido. */
    public function testOnlyAVersionShapedPrefixIsStripped(): void
    {
        self::assertNull($this->resolve('/v/não-é-uma-versão/main.js'));
        self::assertNull($this->resolve('/v//main.js'));
    }

    private function renderPage(string $assetVersion): string
    {
        $dashboardApiAuthRequired = true;
        $downlinkQueueTtlSeconds = 300;
        $amchartsLicense = '';

        ob_start();
        require dirname(__DIR__, 3) . '/src/Dashboard/index.php';
        return (string)ob_get_clean();
    }

    private function server(): DashboardHttpServer
    {
        return (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();
    }

    private function resolve(string $requestPath): ?string
    {
        $method = new \ReflectionMethod(DashboardHttpServer::class, 'publicAssetPath');
        /** @var string|null $path */
        $path = $method->invoke($this->server(), $requestPath);
        return $path;
    }

    private function serve(string $requestPath): \React\Http\Message\Response
    {
        $path = $this->resolve($requestPath);
        self::assertNotNull($path, sprintf('o recurso %s tinha de existir', $requestPath));

        $method = new \ReflectionMethod(DashboardHttpServer::class, 'staticFile');
        /** @var \React\Http\Message\Response $response */
        $response = $method->invoke($this->server(), $path, new ServerRequest('GET', $requestPath));
        return $response;
    }

    private function fingerprint(string $root): string
    {
        $method = new \ReflectionMethod(DashboardHttpServer::class, 'assetFingerprint');
        /** @var string $fingerprint */
        $fingerprint = $method->invoke(null, $root);
        return $fingerprint;
    }
}
