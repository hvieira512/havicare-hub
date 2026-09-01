<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\UploadedFile;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\ModelService;
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
        self::assertStringContainsString('type="module" src="main.js"', $first);
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
        self::assertStringContainsString('window.hubDashboardApiToken = null;', $first);
        self::assertSame($first, $second);
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

        $logo = $server(new ServerRequest('GET', '/assets/logo.svg'));
        self::assertSame(200, $logo->getStatusCode());
        self::assertSame('image/svg+xml', $logo->getHeaderLine('Content-Type'));
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

    public function testModelImageUploadIsCompressedAndStoredAsGeneratedJpeg(): void
    {
        $source = imagecreatetruecolor(900, 300);
        self::assertNotFalse($source);
        $color = imagecolorallocate($source, 24, 120, 180);
        imagefill($source, 0, 0, $color);
        ob_start();
        imagepng($source);
        $bytes = (string)ob_get_clean();

        $upload = new UploadedFile(Utils::streamFor($bytes), strlen($bytes), UPLOAD_ERR_OK, 'watch.png', 'image/png');
        $api = new ModelService(ApiDataAccess::fromDatabase($this->createDashboardDatabase()));
        $method = new \ReflectionMethod(ModelService::class, 'storeModelImage');

        $route = $method->invoke($api, $upload);
        self::assertIsString($route);
        self::assertMatchesRegularExpression('#^/model-images/[a-f0-9]{32}\.jpg$#', $route);

        $path = __DIR__ . '/../../../var/dashboard/model-images/' . basename($route);
        try {
            self::assertFileExists($path);
            $imageInfo = getimagesize($path);
            self::assertIsArray($imageInfo);
            self::assertSame(IMAGETYPE_JPEG, $imageInfo[2] ?? null);
            [$width, $height] = $imageInfo;
            self::assertSame(640, $width);
            self::assertSame(213, $height);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testModelImageUploadStripsPngColorProfileChunksBeforeDecode(): void
    {
        $source = imagecreatetruecolor(20, 20);
        self::assertNotFalse($source);
        $color = imagecolorallocate($source, 200, 40, 40);
        imagefill($source, 0, 0, $color);
        ob_start();
        imagepng($source);
        $bytes = $this->insertPngChunk((string)ob_get_clean(), 'iCCP', "profile\0\0invalid-profile");

        $upload = new UploadedFile(Utils::streamFor($bytes), strlen($bytes), UPLOAD_ERR_OK, 'watch.png', 'image/png');
        $api = new ModelService(ApiDataAccess::fromDatabase($this->createDashboardDatabase()));
        $method = new \ReflectionMethod(ModelService::class, 'storeModelImage');

        $route = $method->invoke($api, $upload);
        self::assertIsString($route);
        self::assertMatchesRegularExpression('#^/model-images/[a-f0-9]{32}\.jpg$#', $route);

        $path = __DIR__ . '/../../../var/dashboard/model-images/' . basename($route);
        try {
            self::assertFileExists($path);
            $imageInfo = getimagesize($path);
            self::assertIsArray($imageInfo);
            self::assertSame(IMAGETYPE_JPEG, $imageInfo[2] ?? null);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function insertPngChunk(string $png, string $type, string $data): string
    {
        $signatureLength = 8;
        $chunk = pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return substr($png, 0, $signatureLength) . $chunk . substr($png, $signatureLength);
    }
}
