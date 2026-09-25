<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Dashboard\DashboardHttpServer;
use Tests\Support\DashboardHttpTestCase;

/**
 * O que o servidor entrega: a página, os recursos estáticos, as imagens dos modelos e as
 * regras de cache das rotas.
 *
 * A autenticação está no `DashboardApiAuthTest`, o isolamento entre clientes no
 * `DashboardApiTenancyTest`, o detalhe do dispositivo no `DashboardDeviceDetailTest` e o
 * streaming no `DashboardStreamTest`.
 */
final class DashboardHttpServerTest extends DashboardHttpTestCase
{
    public function testDashboardPageRendersPhpComponentsRepeatedly(): void
    {
        $server = (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();
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

    /**
     * O `saveDevice()` grava identidade, licença e gateways -- nada de configurações. No
     * rodapé do modal era o botão mais proeminente da caixa a prometer gravar o que estivesse
     * à vista, incluindo o separador das configurações, onde cada bloco tem o seu «Enviar».
     */
    public function testDeviceModalKeepsGeneralTabActionsInsideTheGeneralTab(): void
    {
        $server = (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();
        $page = (new \ReflectionMethod(DashboardHttpServer::class, 'page'))->invoke($server);

        $generalPane = self::sliceBetween($page, 'id="deviceGeneralPane"', 'id="deviceConfigPane"');
        self::assertStringContainsString('id="saveDeviceBtn"', $generalPane);
        self::assertStringContainsString('id="deleteDeviceBtn"', $generalPane);

        $footer = self::sliceBetween($page, 'id="deviceGeneralPane"', 'id="deviceWizardModal"');
        $footer = substr($footer, (int) strpos($footer, 'modal-footer'));
        self::assertStringNotContainsString('id="saveDeviceBtn"', $footer);
        self::assertStringNotContainsString('id="deleteDeviceBtn"', $footer);
        self::assertStringContainsString('Fechar', $footer);
    }

    /** As etiquetas que o utilizador lê são portuguesas; o nome do campo no fio não muda. */
    public function testDeviceModalLabelsAreWrittenInPortuguese(): void
    {
        $server = (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();
        $page = (new \ReflectionMethod(DashboardHttpServer::class, 'page'))->invoke($server);

        self::assertStringNotContainsString('>Device ID<', $page);
        self::assertStringContainsString('>ID do dispositivo<', $page);
    }

    private static function sliceBetween(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        $end = strpos($haystack, $to, $start === false ? 0 : $start);
        self::assertIsInt($start, "não encontrei {$from}");
        self::assertIsInt($end, "não encontrei {$to}");

        return substr($haystack, $start, $end - $start);
    }

    public function testDashboardOnlyServesExplicitPublicAssets(): void
    {
        $server = (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();

        $stylesheet = $server(new ServerRequest('GET', '/main.css'));
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
        $server = (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();

        $stylesheet = $server(new ServerRequest('GET', '/main.css'));
        $etag = $stylesheet->getHeaderLine('ETag');
        self::assertNotSame('', $etag);
        self::assertSame('no-cache', $stylesheet->getHeaderLine('Cache-Control'));

        $revalidated = $server(new ServerRequest('GET', '/main.css', ['If-None-Match' => $etag]));
        self::assertSame(304, $revalidated->getStatusCode());
        self::assertSame('', (string)$revalidated->getBody());

        $vendor = $server(new ServerRequest('GET', '/assets/vendor/sweetalert2/sweetalert2.all.min.js'));
        self::assertSame(200, $vendor->getStatusCode());
        self::assertSame('public, max-age=31536000, immutable', $vendor->getHeaderLine('Cache-Control'));
        self::assertSame('', $vendor->getHeaderLine('ETag'));
    }

    public function testDashboardDoesNotExposeSourceOrFilesOutsidePublicAssetRoots(): void
    {
        $server = (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();

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
}
