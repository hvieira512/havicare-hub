<?php

namespace Tests\Unit\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\UploadedFile;
use Hub\Api\Routes\Models;
use Hub\Dashboard\ApiTokenStore;
use Hub\Dashboard\DashboardDataAccess;
use Hub\Dashboard\DashboardDatabase;
use Hub\Dashboard\DashboardHttpServer;
use Hub\Dashboard\DashboardStore;
use Hub\Registry\Whitelist;
use PHPUnit\Framework\TestCase;

final class DashboardHttpServerTest extends TestCase
{
    private string $whitelistPath;
    private string $databasePath;

    protected function setUp(): void
    {
        $this->whitelistPath = sys_get_temp_dir() . '/hub-dashboard-http-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        $this->databasePath = sys_get_temp_dir() . '/hub-dashboard-http-db-' . bin2hex(random_bytes(4)) . '.sqlite';
        file_put_contents($this->whitelistPath, json_encode([
            '861265061009822' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '1001'],
            '861265061009833' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '2002'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (is_file($this->whitelistPath)) {
            unlink($this->whitelistPath);
        }
        foreach ([$this->databasePath, $this->databasePath . '-shm', $this->databasePath . '-wal'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testDashboardPageRendersPhpComponentsRepeatedly(): void
    {
        $server = (new \ReflectionClass(DashboardHttpServer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DashboardHttpServer::class, 'page');

        $first = $method->invoke($server);
        $second = $method->invoke($server);

        self::assertIsString($first);
        self::assertStringContainsString('id="telemetry"', $first);
        self::assertStringContainsString('type="module" src="main.js"', $first);
        self::assertStringContainsString('id="deviceSelectorModal"', $first);
        self::assertStringContainsString('id="deviceSelectionEmptyState"', $first);
        self::assertSame($first, $second);
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
        $api = new Models(DashboardDataAccess::fromDatabase(new DashboardDatabase('file::memory:?cache=shared')));
        $method = new \ReflectionMethod(Models::class, 'storeModelImage');

        $route = $method->invoke($api, $upload);
        self::assertIsString($route);
        self::assertMatchesRegularExpression('#^/model-images/[a-f0-9]{32}\.jpg$#', $route);

        $path = __DIR__ . '/../../../var/dashboard/model-images/' . basename($route);
        try {
            self::assertFileExists($path);
            self::assertSame(IMAGETYPE_JPEG, exif_imagetype($path));
            [$width, $height] = getimagesize($path);
            self::assertSame(640, $width);
            self::assertSame(213, $height);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testApiLoginIssuesBearerTokenAndAllowsApiAccess(): void
    {
        $server = $this->makeServer();

        $login = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'admin', 'password' => 'secret'], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $login->getStatusCode(), (string)$login->getBody());
        $payload = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $token = (string)($payload['token']['access_token'] ?? '');
        self::assertNotSame('', $token);

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTenantClientTokenCanUseAllowedDeviceRoutes(): void
    {
        $server = $this->makeServer();

        $login = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'tenant', 'password' => 'tenant-secret'], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $login->getStatusCode(), (string)$login->getBody());
        $payload = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('license_client', $payload['token']['role'] ?? null);
        self::assertSame('1001', $payload['token']['license_id'] ?? null);
        $token = (string)($payload['token']['access_token'] ?? '');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTenantClientTokenOnlyListsItsLicenseDevices(): void
    {
        $server = $this->makeServer();

        $payload = json_decode((string)$server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'tenant', 'password' => 'tenant-secret'], JSON_THROW_ON_ERROR)
        ))->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $token = (string)($payload['token']['access_token'] ?? '');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['861265061009822'], array_map(
            static fn(array $device): string => (string)$device['imei'],
            $body['data'] ?? []
        ));
    }

    public function testTenantClientTokenCannotReadOtherLicenseDevice(): void
    {
        $server = $this->makeServer();

        $payload = json_decode((string)$server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'tenant', 'password' => 'tenant-secret'], JSON_THROW_ON_ERROR)
        ))->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $token = (string)($payload['token']['access_token'] ?? '');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009833',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testTenantClientTokenCannotUseAdminRoutes(): void
    {
        $server = $this->makeServer();

        $login = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'tenant', 'password' => 'tenant-secret'], JSON_THROW_ON_ERROR)
        ));
        $payload = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $token = (string)($payload['token']['access_token'] ?? '');

        $response = $server(new ServerRequest(
            'DELETE',
            '/api/models/1',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testApiRejectsBasicAuthWhileDashboardAcceptsIt(): void
    {
        $server = $this->makeServer();
        $basic = 'Basic ' . base64_encode('admin:secret');

        $apiResponse = $server(new ServerRequest('GET', '/api/devices', ['Authorization' => $basic]));
        self::assertSame(401, $apiResponse->getStatusCode());

        $dashboardResponse = $server(new ServerRequest('GET', '/dashboard', ['Authorization' => $basic]));
        self::assertSame(200, $dashboardResponse->getStatusCode());
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
        $api = new Models(DashboardDataAccess::fromDatabase(new DashboardDatabase('file::memory:?cache=shared')));
        $method = new \ReflectionMethod(Models::class, 'storeModelImage');

        $route = $method->invoke($api, $upload);
        self::assertIsString($route);
        self::assertMatchesRegularExpression('#^/model-images/[a-f0-9]{32}\.jpg$#', $route);

        $path = __DIR__ . '/../../../var/dashboard/model-images/' . basename($route);
        try {
            self::assertFileExists($path);
            self::assertSame(IMAGETYPE_JPEG, exif_imagetype($path));
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

    private function makeServer(): DashboardHttpServer
    {
        $redis = new InMemoryRedisClientForDevicesApi();
        $db = DashboardDataAccess::fromDatabase(new DashboardDatabase($this->databasePath));
        $db->apiUsers->create('tenant', password_hash('tenant-secret', PASSWORD_DEFAULT), 'license_client', '1001', true);
        $store = new DashboardStore($redis, prefix: 'test:dashboard:http');
        $store->setDataAccess($db);
        $store->registerDevice('861265061009822', 'Vivistar', 'L08 Pro', 'watch', '1001');
        $store->registerDevice('861265061009833', 'Vivistar', 'L08 Pro', 'watch', '2002');

        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturn('sent');

        return new DashboardHttpServer(
            $store,
            new ApiTokenStore($redis, 'test:api-tokens'),
            new Whitelist($this->whitelistPath),
            $hub,
            null,
            $db,
            'admin',
            'secret',
            'tenant',
            'tenant-secret',
            3600
        );
    }
}
