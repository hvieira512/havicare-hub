<?php

declare(strict_types=1);

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Dashboard\DashboardHttpOptions;
use Hub\Dashboard\DashboardHttpServer;
use Tests\Support\DashboardHttpTestCase;

/** A página, os estáticos, as imagens dos modelos e as regras de cache que o servidor entrega. */
final class DashboardHttpServerTest extends DashboardHttpTestCase
{
    public function testDashboardPageRendersPhpComponentsRepeatedly(): void
    {
        $server = self::assetServer();
        $method = new \ReflectionMethod(DashboardHttpServer::class, 'page');

        $first = $method->invoke($server);
        $second = $method->invoke($server);

        self::assertIsString($first);
        self::assertStringContainsString('id="telemetryPager"', $first);
        // O ponto de entrada leva a impressão digital do conjunto, e os `import` relativos
        // herdam-na: é o que impede um arranque de misturar módulos de duas versões.
        self::assertMatchesRegularExpression(
            '#type="module" src="/v/[0-9a-f]{6,64}/main\.js"#',
            $first,
        );
        self::assertStringContainsString('id="deviceSelectorModal"', $first);
        // Adicionar e editar são dois modais, e a página inclui os dois.
        self::assertStringContainsString('id="deviceWizardModal"', $first);
        self::assertStringContainsString('id="deviceModal"', $first);
        self::assertStringContainsString('id="deviceSelectionEmptyState"', $first);
        self::assertStringContainsString('id="capabilitySupplierButtons"', $first);
        self::assertStringContainsString('id="capabilityCatalogViewer"', $first);
        self::assertStringContainsString('id="dashboardLoginForm"', $first);
        self::assertStringContainsString('id="dashboardLoginSubmit"', $first);
        self::assertStringContainsString('/assets/vendor/sweetalert2/sweetalert2.all.min.js', $first);
        // Nenhum recurso vem de fora: é o que cai se alguém voltar a colar uma etiqueta de CDN.
        self::assertDoesNotMatchRegularExpression('#(?:src|href)="(?:https?:)?//#', $first);
        self::assertStringContainsString('data-dashboard-auth-required="true"', $first);
        // O tema carrega antes das folhas: se a etiqueta desaparecer, a página abre com o
        // tema errado e corrige-se à frente de quem olha.
        self::assertMatchesRegularExpression(
            '#<script src="/v/[0-9a-f]{6,64}/assets/js/theme-init\.js"></script>#',
            $first,
        );
        self::assertSame($first, $second);
    }

    public function testDashboardOnlyServesExplicitPublicAssets(): void
    {
        $server = self::assetServer();

        $stylesheet = $server(new ServerRequest('GET', '/assets/css/modals.css'));
        self::assertSame(200, $stylesheet->getStatusCode());
        self::assertSame('text/css', $stylesheet->getHeaderLine('Content-Type'));

        $module = $server(new ServerRequest('GET', '/dashboard/app.js'));
        self::assertSame(200, $module->getStatusCode());
        self::assertSame('application/javascript', $module->getHeaderLine('Content-Type'));

        $logo = $server(new ServerRequest('GET', '/assets/logo.png'));
        self::assertSame(200, $logo->getStatusCode());
        self::assertSame('image/png', $logo->getHeaderLine('Content-Type'));

        $themeInit = $server(new ServerRequest('GET', '/assets/js/theme-init.js'));
        self::assertSame(200, $themeInit->getStatusCode());
        self::assertSame('application/javascript', $themeInit->getHeaderLine('Content-Type'));
    }

    public function testOwnAssetsRevalidateWhileVendorAssetsAreImmutable(): void
    {
        $server = self::assetServer();

        $stylesheet = $server(new ServerRequest('GET', '/assets/css/modals.css'));
        $etag = $stylesheet->getHeaderLine('ETag');
        self::assertNotSame('', $etag);
        self::assertSame('no-cache', $stylesheet->getHeaderLine('Cache-Control'));

        $revalidated = $server(new ServerRequest('GET', '/assets/css/modals.css', ['If-None-Match' => $etag]));
        self::assertSame(304, $revalidated->getStatusCode());
        self::assertSame('', (string)$revalidated->getBody());

        $vendor = $server(new ServerRequest('GET', '/assets/vendor/sweetalert2/sweetalert2.all.min.js'));
        self::assertSame(200, $vendor->getStatusCode());
        self::assertSame('public, max-age=31536000, immutable', $vendor->getHeaderLine('Cache-Control'));
        self::assertSame('', $vendor->getHeaderLine('ETag'));
    }

    public function testDashboardDoesNotExposeSourceOrFilesOutsidePublicAssetRoots(): void
    {
        $server = self::assetServer();

        foreach (
            [
            '/DashboardHttpServer.php',
            '/index.php',
            '/../../.env',
            '/dashboard/../../../Config.php',
            '/dashboard/%2e%2e/%2e%2e/Config.php',
            ] as $path
        ) {
            $response = $server(new ServerRequest('GET', $path));
            self::assertSame(404, $response->getStatusCode(), $path);
        }
    }

    /** Abaixo dos 992px o cartão de login tem o seu `h1`, porque o painel desaparece. */
    public function testTheLoginCardCarriesAHeadingForTheWidthsWithoutThePanel(): void
    {
        $server = self::assetServer();

        $html = (string)$server(new ServerRequest('GET', '/dashboard'))->getBody();
        $start = strpos($html, 'dashboard-login-card');
        self::assertIsInt($start);
        $card = substr($html, $start, (int)strpos($html, '</form>', $start) - $start);

        self::assertMatchesRegularExpression('/<h1[^>]*>[^<]+<\/h1>/', $card);
    }

    public function testCatalogRoutesRevalidateWhileDeviceRoutesDoNot(): void
    {
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'admin', 'secret');

        $models = $server(new ServerRequest('GET', '/api/models', ['Authorization' => 'Bearer ' . $token]));
        self::assertSame(200, $models->getStatusCode(), (string)$models->getBody());
        $etag = $models->getHeaderLine('ETag');
        self::assertNotSame('', $etag);
        self::assertSame('no-cache', $models->getHeaderLine('Cache-Control'));

        $revalidated = $server(new ServerRequest(
            'GET',
            '/api/models',
            ['Authorization' => 'Bearer ' . $token, 'If-None-Match' => $etag]
        ));
        self::assertSame(304, $revalidated->getStatusCode());
        self::assertSame('', (string)$revalidated->getBody());

        $devices = $server(new ServerRequest('GET', '/api/devices', ['Authorization' => 'Bearer ' . $token]));
        self::assertSame(200, $devices->getStatusCode(), (string)$devices->getBody());
        self::assertSame('', $devices->getHeaderLine('ETag'));
    }

    /** Só o que serve a página e os estáticos, sem a pilha da API. */
    private static function assetServer(): DashboardHttpServer
    {
        $server = (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(DashboardHttpServer::class, 'options'))->setValue($server, new DashboardHttpOptions());

        return $server;
    }
}
