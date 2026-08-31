<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\UploadedFile;
use Hub\Api\Auth\ApiTokenStore;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Services\ModelService;
use Hub\Dashboard\DashboardHttpServer;
use Hub\Dashboard\DashboardStore;
use Hub\Log\Logger;
use Hub\PendingDownlink;
use Hub\PendingDownlinkQueue;
use Hub\Registry\Whitelist;
use Hub\Runtime\DashboardServerFactory;
use React\EventLoop\Loop;
use Tests\Support\MysqlDashboardTestCase;
use Tests\Support\Doubles\InMemoryRedisClient;
use Tests\Support\Doubles\IngressFixtures;
use Tests\Support\Doubles\WavFixture;

final class DashboardHttpServerTest extends MysqlDashboardTestCase
{
    private string $whitelistPath;
    private string $apiLogPath;
    private string|false $originalLogFile;
    private string|false $originalLogFileApi;
    private string|false $originalLogLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLogFile = getenv('LOG_FILE');
        $this->originalLogFileApi = getenv('LOG_FILE_API');
        $this->originalLogLevel = getenv('LOG_LEVEL');
        $this->apiLogPath = sys_get_temp_dir() . '/hub-dashboard-api-log-' . bin2hex(random_bytes(4)) . '.log';
        putenv('LOG_FILE=' . $this->apiLogPath);
        putenv('LOG_FILE_API=' . $this->apiLogPath);
        putenv('LOG_LEVEL=info');
        Logger::reset();
        $this->whitelistPath = IngressFixtures::whitelistPath([
            '861265061009822' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '1001', 'company' => 'hitcare'],
            '861265061009833' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '2002', 'company' => 'otherCare'],
            '861265061009844' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '0', 'company' => 'null'],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->apiLogPath)) {
            unlink($this->apiLogPath);
        }
        putenv($this->originalLogFile === false ? 'LOG_FILE' : 'LOG_FILE=' . $this->originalLogFile);
        putenv($this->originalLogFileApi === false ? 'LOG_FILE_API' : 'LOG_FILE_API=' . $this->originalLogFileApi);
        putenv($this->originalLogLevel === false ? 'LOG_LEVEL' : 'LOG_LEVEL=' . $this->originalLogLevel);
        Logger::reset();
        parent::tearDown();
    }

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
        $refreshToken = (string)($payload['token']['refresh_token'] ?? '');
        self::assertSame('admin', $payload['token']['username'] ?? null);
        self::assertNotSame('', $token);
        self::assertNotSame('', $refreshToken);

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(200, $response->getStatusCode());

        $refreshAsBearer = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $refreshToken]
        ));
        self::assertSame(401, $refreshAsBearer->getStatusCode(), (string)$refreshAsBearer->getBody());

        $filters = $server(new ServerRequest(
            'GET',
            '/api/device-types/suppliers',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(200, $filters->getStatusCode(), (string)$filters->getBody());
    }

    public function testApiRefreshTokenReissuesAccessAfterAccessTokenExpires(): void
    {
        $server = $this->makeServer(apiTokenTtlSeconds: 1, apiRefreshTokenTtlSeconds: 60);
        $login = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'admin', 'password' => 'secret'], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $login->getStatusCode(), (string)$login->getBody());
        $loginPayload = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $expiredAccessToken = (string)($loginPayload['token']['access_token'] ?? '');
        $refreshToken = (string)($loginPayload['token']['refresh_token'] ?? '');

        self::assertNotSame('', $expiredAccessToken);
        self::assertNotSame('', $refreshToken);
        sleep(2);

        $expiredResponse = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $expiredAccessToken]
        ));

        self::assertSame(401, $expiredResponse->getStatusCode(), (string)$expiredResponse->getBody());

        $refresh = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['refresh_token' => $refreshToken], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $refresh->getStatusCode(), (string)$refresh->getBody());
        $payload = json_decode((string)$refresh->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $newToken = (string)($payload['token']['access_token'] ?? '');
        $newRefreshToken = (string)($payload['token']['refresh_token'] ?? '');

        self::assertNotSame('', $newToken);
        self::assertNotSame('', $newRefreshToken);
        self::assertNotSame($expiredAccessToken, $newToken);
        self::assertNotSame($refreshToken, $newRefreshToken);

        $log = $this->apiLogContents();
        self::assertStringNotContainsString($expiredAccessToken, $log);
        self::assertStringNotContainsString($refreshToken, $log);
        self::assertStringNotContainsString($newToken, $log);
        self::assertStringNotContainsString($newRefreshToken, $log);
        self::assertStringContainsString('"refresh_token":"********"', $log);

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $newToken]
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
    }

    public function testApiLoginLogsStructuredBodyAndReturnsRequestIdHeader(): void
    {
        $server = $this->makeServer();
        $body = json_encode(['username' => 'admin', 'password' => 'secret'], JSON_THROW_ON_ERROR);
        $requestId = 'req-login-123';

        $response = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json', 'X-Request-Id' => $requestId],
            $body
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame($requestId, $response->getHeaderLine('X-Request-Id'));

        $log = $this->apiLogContents();
        self::assertStringContainsString('API login accepted', $log);
        self::assertStringContainsString('API request completed', $log);
        self::assertStringContainsString('"request_id":"' . $requestId . '"', $log);
        self::assertStringContainsString(
            '"request_body":{"username":"admin","password":"********"}',
            $log
        );
        self::assertStringNotContainsString('"password":"secret"', $log);
        self::assertStringContainsString('"response_content_type":"application/json"', $log);
        self::assertStringContainsString('"response_body":{"status":"ok","token":', $log);

        $responsePayload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString((string)$responsePayload['token']['access_token'], $log);
        self::assertStringNotContainsString((string)$responsePayload['token']['refresh_token'], $log);
        self::assertStringContainsString('"access_token":"********"', $log);
        self::assertStringContainsString('"refresh_token":"********"', $log);
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

    public function testApiLoggingRedactsBeforeCappingAnOversizedBody(): void
    {
        $server = $this->makeServer();
        $body = json_encode([
            'username' => 'admin',
            'password' => 'secret',
            'filler' => str_repeat('a', 40000),
        ], JSON_THROW_ON_ERROR);

        $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            $body
        ));

        $log = $this->apiLogContents();
        // O corpo cortado viaja como texto dentro do registo, e por isso com as aspas escapadas.
        self::assertStringContainsString('bytes no total]', $log);
        self::assertStringContainsString('password\":\"********', $log);
        self::assertStringNotContainsString('secret', $log);
    }

    public function testApiLoggingKeepsFullBodiesWithoutEllipsis(): void
    {
        $payload = str_repeat('abc123', 800);
        Logger::channel('api')->info('large body', ['response_body' => ['payload' => $payload]]);

        $log = $this->apiLogContents();
        self::assertStringContainsString('"payload":"' . $payload . '"', $log);
        self::assertStringNotContainsString('...', $log);
        self::assertGreaterThan(4096, strlen($log));
    }

    public function testLoggerStopsNormalizingUnboundedlyDeepContext(): void
    {
        $value = ['leaf' => 'complete'];
        for ($depth = 0; $depth < 40; $depth++) {
            $value = ['nested' => $value];
        }

        Logger::channel('api')->info('deep context', ['body' => $value]);

        $log = $this->apiLogContents();
        self::assertStringContainsString('levels deep, aborting normalization', $log);
        self::assertStringNotContainsString('"leaf":"complete"', $log);
    }

    public function testLoggerKeepsTheDepthOfARealDeviceResponse(): void
    {
        $value = ['leaf' => 'complete'];
        for ($depth = 0; $depth < 12; $depth++) {
            $value = ['nested' => $value];
        }

        Logger::channel('api')->info('device response', ['body' => $value]);

        $log = $this->apiLogContents();
        self::assertStringContainsString('"leaf":"complete"', $log);
        self::assertStringNotContainsString('aborting normalization', $log);
    }

    public function testUnauthorizedApiRequestIsStillLogged(): void
    {
        $server = $this->makeServer();

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices'
        ));

        self::assertSame(401, $response->getStatusCode(), (string)$response->getBody());
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));

        $log = $this->apiLogContents();
        self::assertStringContainsString('API request completed', $log);
        self::assertStringContainsString('"status":401', $log);
        self::assertStringContainsString('"auth_state":"missing"', $log);
        self::assertStringContainsString('"response_content_type":"application/json"', $log);
        self::assertStringContainsString('"response_body":{"error":{"code":"unauthorized"', $log);
    }

    public function testApiDocsAndOpenApiJsonArePublicWhileDevicesRemainProtected(): void
    {
        $server = $this->makeServer();

        $docs = $server(new ServerRequest('GET', '/api/docs'));
        self::assertSame(200, $docs->getStatusCode(), (string)$docs->getBody());
        self::assertSame('text/html; charset=utf-8', $docs->getHeaderLine('Content-Type'));

        $openapi = $server(new ServerRequest('GET', '/api/openapi.json'));
        self::assertSame(200, $openapi->getStatusCode(), (string)$openapi->getBody());
        self::assertSame('application/json', $openapi->getHeaderLine('Content-Type'));

        $protected = $server(new ServerRequest('GET', '/api/devices'));
        self::assertSame(401, $protected->getStatusCode(), (string)$protected->getBody());
    }

    public function testOpenApiSpecAdvertisesBearerAuth(): void
    {
        $spec = \Hub\Api\OpenApiSpec::get();

        self::assertSame(['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => 'Use the bearer token returned by /api/auth/login.']], $spec['components']['securitySchemes'] ?? null);
        self::assertSame([['bearerAuth' => []]], $spec['security'] ?? null);
        self::assertSame([], $spec['paths']['/api/auth/login']['post']['security'] ?? null);
        self::assertArrayNotHasKey('/api/auth/refresh', $spec['paths']);
        self::assertSame([], $spec['paths']['/api/openapi.json']['get']['security'] ?? null);
        self::assertSame([], $spec['paths']['/api/docs']['get']['security'] ?? null);
        self::assertArrayHasKey('/api/devices/{imei}/configurations', $spec['paths']);
    }

    public function testApiCanBeExposedWithoutAuthForDevelopment(): void
    {
        $server = $this->makeServer(apiAuthRequired: false);

        $response = $server(new ServerRequest('GET', '/api/devices'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
    }

    public function testCreateDeviceReturnsConflictWhenImeiAlreadyExists(): void
    {
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'POST',
            '/api/devices',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode([
                'imei' => '861265061009822',
                'supplier' => 'Vivistar',
                'model' => 'L08 Pro',
                'deviceType' => 'watch',
                'licenseId' => '0',
            ], JSON_THROW_ON_ERROR)
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('device_exists', $body['error']['code'] ?? null);
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
        self::assertSame(1001, $payload['token']['license_id'] ?? null);
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

    /**
     * O `licenseId` chega ao registo como inteiro pela API e como texto pelo ficheiro da
     * whitelist, e o isolamento entre clientes não pode depender de qual dos dois.
     */
    public function testTenantIsolationHoldsWhateverTypeTheLicenseIdArrivesAs(): void
    {
        foreach ([['1001', '2002'], [1001, 2002]] as [$mine, $theirs]) {
            [$server, $db, $store] = $this->makeServerWithDatabase();
            $store->registerDevice('861265061009866', 'Vivistar', 'L08 Pro', 'watch', $mine, '', '', 'hitcare');
            $db->whitelist->register('861265061009866', 'Vivistar', 'L08 Pro', 'watch', $mine, '', '', 'hitcare');
            $store->registerDevice('861265061009877', 'Vivistar', 'L08 Pro', 'watch', $theirs, '', '', 'otherCare');
            $db->whitelist->register('861265061009877', 'Vivistar', 'L08 Pro', 'watch', $theirs, '', '', 'otherCare');

            $token = $this->loginToken($server, 'tenant', 'tenant-secret');
            $label = is_string($mine) ? 'string' : 'int';

            $mineResponse = $server(new ServerRequest(
                'GET',
                '/api/devices/861265061009866',
                ['Authorization' => 'Bearer ' . $token]
            ));
            self::assertSame(200, $mineResponse->getStatusCode(), "own device, {$label} licenseId");

            $theirsResponse = $server(new ServerRequest(
                'GET',
                '/api/devices/861265061009877',
                ['Authorization' => 'Bearer ' . $token]
            ));
            self::assertSame(404, $theirsResponse->getStatusCode(), "other tenant's device, {$label} licenseId");
        }
    }

    /**
     * Um `hub_admin` não tem licença, e a tabela di-lo com NULL como já dizia no
     * `license_ref_id`. O `license_client` ao lado prova que a licença a sério continua lá.
     */
    public function testAdminHasNoLicenceInTheTableAndTheClientKeepsItsOwn(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();

        $rows = [];
        foreach ($db->apiUsers->all() as $user) {
            $rows[(string)$user['username']] = $user;
        }
        self::assertNull($rows['admin']['license_id']);
        self::assertNull($rows['admin']['license_ref_id']);
        self::assertSame(1001, (int)$rows['tenant']['license_id']);

        // E o token emitido a partir disso continua a dizer o mesmo.
        $admin = $this->loginPayload($server, 'admin', 'secret');
        self::assertArrayHasKey('license_id', $admin['token']);
        self::assertNull($admin['token']['license_id']);
        $tenant = $this->loginPayload($server, 'tenant', 'tenant-secret');
        self::assertSame(1001, $tenant['token']['license_id']);
    }

    public function testDeviceWithoutALicenseIsInvisibleToATenantClient(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $store->registerDevice('861265061009888', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');
        $db->whitelist->register('861265061009888', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009888',
            ['Authorization' => 'Bearer ' . $token]
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * O inverso do teste abaixo, e o que exercita de facto o âmbito por licença: mesma
     * empresa, licença diferente. O âmbito por empresa não apanha isto, e sem este teste a
     * cláusula da licença podia desaparecer inteira com todos os outros a passar.
     */
    public function testTenantClientCannotAccessAnotherLicenseWithinItsOwnCompany(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $hitcareId = $db->companies->create('hitcare-extra-holder');
        $db->licenses->create($hitcareId, '3003', 'hitcare-second-license');

        // A mesma empresa do cliente, e uma licença que ele não tem.
        $store->registerDevice('861265061009899', 'Vivistar', 'L08 Pro', 'watch', 3003, '', '', 'hitcare');
        $db->whitelist->register('861265061009899', 'Vivistar', 'L08 Pro', 'watch', 3003, '', '', 'hitcare');

        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $list = $server(new ServerRequest('GET', '/api/devices', ['Authorization' => 'Bearer ' . $token]));
        $imeis = array_map(
            static fn(array $device): string => (string)$device['imei'],
            json_decode((string)$list->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'] ?? []
        );
        self::assertNotContains('861265061009899', $imeis, 'listing must be scoped by license, not company alone');

        $detail = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009899',
            ['Authorization' => 'Bearer ' . $token]
        ));
        self::assertSame(404, $detail->getStatusCode());
    }

    public function testTenantClientCannotAccessSameLicenseNumberFromAnotherCompany(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $store->registerDevice('861265061009855', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'otherCare');
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $db->whitelist->register('861265061009855', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'otherCare');
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $list = $server(new ServerRequest('GET', '/api/devices', ['Authorization' => 'Bearer ' . $token]));
        $payload = json_decode((string)$list->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['861265061009822'], array_map(
            static fn(array $device): string => (string)$device['imei'],
            $payload['data'] ?? []
        ));

        $detail = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009855',
            ['Authorization' => 'Bearer ' . $token]
        ));
        self::assertSame(404, $detail->getStatusCode());
    }

    public function testAdminCanFilterDevicesByCompany(): void
    {
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices?company=hitcare',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('hitcare', $body['filters']['applied']['company'] ?? null);
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

    public function testTenantClientCanPutConfigurationButCannotUpdateDeviceMetadata(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $configResponse = $server(new ServerRequest(
            'PATCH',
            '/api/devices/861265061009822/configurations',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode([
                'configurations' => [
                    'fall_detection' => ['enabled' => true],
                ],
            ], JSON_THROW_ON_ERROR)
        ));
        $configBody = json_decode((string)$configResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $configResponse->getStatusCode(), (string)$configResponse->getBody());
        self::assertSame('ok', $configBody['status'] ?? null);

        $metadataResponse = $server(new ServerRequest(
            'PUT',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode([
                'imei' => '861265061009822',
                'supplier' => 'Vivistar',
                'model' => 'L08 Pro',
                'licenseId' => '2002',
            ], JSON_THROW_ON_ERROR)
        ));
        $metadataBody = json_decode((string)$metadataResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $metadataResponse->getStatusCode(), (string)$metadataResponse->getBody());
        self::assertSame('forbidden', $metadataBody['error']['code'] ?? null);
    }

    public function testConfigurationPatchLogsStructuredBodies(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');
        $body = json_encode([
            'configurations' => [
                'fall_detection' => ['enabled' => true],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $server(new ServerRequest(
            'PATCH',
            '/api/devices/861265061009822/configurations',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'X-Request-Id' => 'req-config-1'],
            $body
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $log = $this->apiLogContents();
        self::assertStringContainsString('API device configuration processed', $log);
        self::assertStringContainsString('"request_id":"req-config-1"', $log);
        self::assertStringContainsString('"request_body":' . $body, $log);
        self::assertStringContainsString('"path":"/api/devices/861265061009822/configurations"', $log);
        self::assertStringContainsString('"response_content_type":"application/json"', $log);
        self::assertStringContainsString('"response_body":{"status":"ok","results":', $log);
    }

    public function testTenantClientCanAssociateUnassignedDeviceViaPatchEndpoint(): void
    {
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $server(new ServerRequest(
            'PATCH',
            '/api/devices/861265061009844/association',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode(['company' => 'hitcare', 'licenseId' => '1001'], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('hitcare', $body['association']['company'] ?? null);
        self::assertSame(1001, $body['association']['licenseId'] ?? null);

        $deviceResponse = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009844',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $device = json_decode((string)$deviceResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $deviceResponse->getStatusCode(), (string)$deviceResponse->getBody());
        self::assertSame('hitcare', $device['device']['company'] ?? null);
        self::assertSame(1001, $device['device']['licenseId'] ?? null);
    }

    public function testTenantClientCannotAssociateAlreadyAssignedDevice(): void
    {
        $server = $this->makeServer();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $server(new ServerRequest(
            'PATCH',
            '/api/devices/861265061009833/association',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode(['company' => 'hitcare', 'licenseId' => '1001'], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('device_already_associated', $body['error']['code'] ?? null);
    }

    public function testTenantClientCanDeleteItsOwnAssociation(): void
    {
        $server = $this->makeServer();
        $tenantToken = $this->loginToken($server, 'tenant', 'tenant-secret');
        $adminToken = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'DELETE',
            '/api/devices/861265061009822/association',
            ['Authorization' => 'Bearer ' . $tenantToken]
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('null', $body['association']['company'] ?? null);
        self::assertSame(0, $body['association']['licenseId'] ?? null);

        $tenantRead = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $tenantToken]
        ));
        self::assertSame(404, $tenantRead->getStatusCode(), (string)$tenantRead->getBody());

        $adminRead = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $adminToken]
        ));
        $device = json_decode((string)$adminRead->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $adminRead->getStatusCode(), (string)$adminRead->getBody());
        self::assertSame('null', $device['device']['company'] ?? null);
        self::assertSame(0, $device['device']['licenseId'] ?? null);
    }

    public function testDeviceDetailEndpointReturnsSparseCapabilitiesAndStoredValues(): void
    {
        [$server, $db] = $this->makeServerWithDatabase();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], [
            'heart_rate',
            'location',
            'call_whitelist',
            'whitelist_enabled',
            'device_password',
        ]);
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'call_whitelist',
            'vivistar',
            'Vivistar',
            'L08 Pro',
            'CALL_WHITELIST',
            ['fields' => ['|+351922222222']]
        );
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'whitelist_enabled',
            'vivistar',
            'Vivistar',
            'L08 Pro',
            'WHITELIST_ENABLED',
            ['enabled' => true]
        );
        $db->deviceConfigurations->saveDesired(
            '861265061009822',
            'devicePassword',
            'four-p-touch',
            'Vivistar',
            'L08 Pro',
            'PASSWORD',
            ['password' => '2468']
        );

        $token = $this->loginToken($server, 'tenant', 'tenant-secret');
        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            ['supported' => true, 'requestable' => true],
            $body['capabilities']['telemetry']['heart_rate'] ?? null
        );
        self::assertSame(
            ['supported' => true, 'requestable' => true],
            $body['capabilities']['telemetry']['location'] ?? null
        );
        self::assertSame([], $body['capabilities']['alarms'] ?? null);
        self::assertSame(
            [
                ['name' => '', 'phone' => '+351922222222'],
            ],
            $body['capabilities']['contacts']['call_whitelist']['value'] ?? null
        );
        self::assertSame(10, $body['capabilities']['contacts']['call_whitelist']['_meta']['limit'] ?? null);
        self::assertArrayNotHasKey(
            'maxLength',
            $body['capabilities']['contacts']['call_whitelist']['_meta']['name'] ?? []
        );
        self::assertArrayNotHasKey(
            'maxLength',
            $body['capabilities']['contacts']['call_whitelist']['_meta']['phone'] ?? []
        );
        self::assertTrue($body['capabilities']['contacts']['call_whitelist']['_meta']['phone']['asciiOnly'] ?? false);
        self::assertTrue($body['capabilities']['contacts']['whitelist_enabled']['value']['enabled'] ?? false);
        self::assertArrayNotHasKey('_nativeKey', $body['capabilities']['contacts']['whitelist_enabled']);
        self::assertSame(
            ['password' => '2468'],
            $body['capabilities']['settings_system']['device_password']['value'] ?? null
        );
        self::assertArrayNotHasKey('blood_pressure', $body['capabilities']['telemetry'] ?? []);
        self::assertArrayNotHasKey('auto_vitals_interval', $body['capabilities']['health'] ?? []);
        self::assertSame('never_reported', $body['configurationSync']['entries']['contacts']['call_whitelist']['status'] ?? null);
        self::assertSame('never_reported', $body['configurationSync']['entries']['contacts']['whitelist_enabled']['status'] ?? null);
        self::assertSame('never_reported', $body['configurationSync']['entries']['settings_system']['device_password']['status'] ?? null);
        self::assertArrayNotHasKey('pending', $body);
        self::assertArrayNotHasKey('transportPending', $body);
    }

    public function testDeviceDetailAndGenericConfigurationPutExposeNewPendingShape(): void
    {
        $submitted = [];
        $hub = $this->createMock(\Hub\DeviceHubServer::class);
        $hub->method('submitDownlink')->willReturnCallback(function (string $imei, string $bytes) use (&$submitted): string {
            $submitted[] = ['imei' => $imei, 'bytes' => $bytes];
            return 'sent';
        });
        $queue = new class implements PendingDownlinkQueue {
            public function enqueue(string $imei, string $bytes, ?array $command, int $ttlSeconds): PendingDownlink
            {
                return new PendingDownlink($imei, 'dedupe', $bytes, $command, time(), time() + $ttlSeconds);
            }

            public function pendingFor(string $imei): array
            {
                return [
                    new PendingDownlink($imei, 'cfg:BP76', 'IWBP76,...', ['command' => 'BP76'], 1719650000, 1719650300),
                ];
            }

            public function remove(PendingDownlink $downlink): void
            {
            }
        };
        [$server, $db] = $this->makeServerWithDatabase($hub, $queue);
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['fall_detection']);
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $put = $server(new ServerRequest(
            'PATCH',
            '/api/devices/861265061009822/configurations',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode([
                'configurations' => [
                    'fall_detection' => ['enabled' => true],
                ],
            ], JSON_THROW_ON_ERROR)
        ));
        $putBody = json_decode((string)$put->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $put->getStatusCode(), (string)$put->getBody());
        self::assertCount(1, $submitted);
        self::assertStringContainsString('BP76', $submitted[0]['bytes']);
        self::assertSame('awaiting_ack', $putBody['configurationSync']['entries']['alarms']['fall_detection']['status'] ?? null);
        self::assertArrayNotHasKey('transportPending', $putBody);

        $detail = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $detailBody = json_decode((string)$detail->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $detail->getStatusCode(), (string)$detail->getBody());
        self::assertSame(
            ['value' => ['enabled' => true], '_meta' => []],
            $detailBody['capabilities']['alarms']['fall_detection'] ?? null
        );
        self::assertSame('awaiting_ack', $detailBody['configurationSync']['entries']['alarms']['fall_detection']['status'] ?? null);
        self::assertArrayNotHasKey('transportPending', $detailBody);
    }

    public function testDeviceDetailExposesTakePillsMetaForFourPTouch(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);
        $db->deviceConfigurations->saveDesired(
            '868017032159118',
            'takePills',
            'four-p-touch',
            '4P Touch',
            'D46',
            'TAKEPILLS',
            [
                'reminderSettings' => '11:25-1-3-1010101',
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => 'data:audio/wav;base64,' . WavFixture::silenceBase64(),
                'voiceMimeType' => 'audio/wav',
            ]
        );

        $token = $this->loginToken($server, 'tenant', 'tenant-secret');
        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/868017032159118',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            [
                'reminderSettings' => [
                    [
                        'time' => '11:25',
                        'enabled' => true,
                        'frequency' => 3,
                        'custom' => '1010101',
                    ],
                ],
                'number' => 1,
                'reminderText' => 'meds',
                'voiceData' => 'data:audio/wav;base64,' . WavFixture::silenceBase64(),
                'voiceMimeType' => 'audio/wav',
            ],
            $body['capabilities']['alarms']['medication_reminders']['value'] ?? null
        );
        self::assertArrayNotHasKey('_nativeKey', $body['capabilities']['alarms']['medication_reminders']);
        self::assertSame(3, $body['capabilities']['alarms']['medication_reminders']['_meta']['limit'] ?? null);
        self::assertSame(
            [
                ['value' => 1, 'label' => 'Uma vez'],
                ['value' => 2, 'label' => 'Diariamente'],
                ['value' => 3, 'label' => 'Personalizado'],
            ],
            $body['capabilities']['alarms']['medication_reminders']['_meta']['frequency']['options'] ?? null
        );
    }

    public function testDeviceDetailExposesMultipleTakePillsRemindersForFourPTouch(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $model = $db->models->find('4P Touch', 'D46');

        self::assertIsArray($model);
        $store->registerDevice('868017032159118', '4P Touch', 'D46', 'watch', 1001, '', '', 'hitcare');
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['medication_reminders']);
        $db->deviceConfigurations->saveDesired(
            '868017032159118',
            'takePills',
            'four-p-touch',
            '4P Touch',
            'D46',
            'TAKEPILLS',
            [
                'reminderSettings' => '11:25-1-2-14:30-0-1-18:00-1-3-1010101',
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => '',
                'voiceMimeType' => '',
            ]
        );

        $token = $this->loginToken($server, 'tenant', 'tenant-secret');
        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/868017032159118',
            ['Authorization' => 'Bearer ' . $token]
        ));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            [
                'reminderSettings' => [
                    ['time' => '11:25', 'enabled' => true, 'frequency' => 2, 'custom' => ''],
                    ['time' => '14:30', 'enabled' => false, 'frequency' => 1, 'custom' => ''],
                    ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '1010101'],
                ],
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => '',
                'voiceMimeType' => '',
            ],
            $body['capabilities']['alarms']['medication_reminders']['value'] ?? null
        );
        self::assertArrayNotHasKey('_nativeKey', $body['capabilities']['alarms']['medication_reminders']);
    }

    public function testStreamPushesUpdatesAsTheyAreWrittenAndReleasesItsListenerOnClose(): void
    {
        [$server, , $store] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?access_token=' . rawurlencode($token)
        ));
        self::assertSame(200, $response->getStatusCode());

        // Um stream tem um ouvinte só.
        self::assertSame(1, $store->updates()->listenerCount());

        $frames = $this->collectSseFramesUntilUpdate($response, function () use ($store): void {
            $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 71]);
            $store->recordCommand('861265061009822', 'cmd-9', [
                'status' => 'waiting',
                'imei' => '861265061009822',
                'protocol' => 'vivistar',
                'requestId' => 'BPXL',
                'nativeType' => 'BPXL',
                'label' => 'Heart rate',
                'feature' => 'heart_rate',
                'expectedReplyTypes' => [],
                'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
            ]);
        });

        // Chegar dentro do prazo prova o caminho de push: o recurso periódico só dispara
        // depois de `STREAM_FALLBACK_SECONDS`.
        self::assertStringContainsString("event: snapshot\n", $frames);
        self::assertStringContainsString("event: update\n", $frames);

        $update = $this->decodeSseFrame(substr($frames, (int)strpos($frames, 'event: update')));
        // A rajada de escritas colapsa num frame que leva as duas.
        self::assertSame('heart_rate', $update['telemetry'][0]['type'] ?? null);
        self::assertSame('BPXL', $update['commands'][0]['requestId'] ?? null);

        $response->getBody()->close();
        self::assertSame(0, $store->updates()->listenerCount());
    }

    public function testClosingTheStreamDuringABurstLeavesNoTimersOrListeners(): void
    {
        [$server, , $store] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?access_token=' . rawurlencode($token)
        ));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $store->updates()->listenerCount());

        // Agenda um envio e desliga antes de a janela de coalescência dele acabar.
        $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 70]);
        $response->getBody()->close();

        self::assertSame(0, $store->updates()->listenerCount());

        // O envio pendente não pode sobreviver ao fecho: corre-se o loop para além da janela
        // de coalescência para confirmar que nada ficou a segurá-lo aberto.
        $loop = Loop::get();
        $ticks = 0;
        $loop->addPeriodicTimer(0.05, static function ($timer) use ($loop, &$ticks): void {
            if (++$ticks >= 10) {
                $loop->cancelTimer($timer);
                $loop->stop();
            }
        });
        $loop->run();

        // Uma escrita depois do fecho não pode chegar a ninguém.
        $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 71]);
        self::assertSame(0, $store->updates()->listenerCount());
    }

    /**
     * O bilhete abre um stream, e só um.
     *
     * O `EventSource` não deixa pôr cabeçalhos, e por isso a credencial de um stream viaja no
     * URL -- onde fica escrita no registo de acessos de qualquer proxy pelo caminho e no
     * histórico do browser. Enquanto o que ia ali era o token de acesso, era uma credencial
     * de uma hora, boa para toda a API, a ficar guardada nesses sítios. O bilhete vale
     * segundos e queima-se à primeira utilização.
     */
    public function testAStreamTicketOpensOneStreamAndIsThenSpent(): void
    {
        [$server] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');

        $minted = $server(
            (new ServerRequest('POST', '/api/auth/stream-ticket'))
                ->withHeader('Authorization', 'Bearer ' . $token)
        );
        self::assertSame(200, $minted->getStatusCode());

        $ticket = (string)(json_decode((string)$minted->getBody(), true)['data']['ticket'] ?? '');
        self::assertNotSame('', $ticket);

        $opened = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?ticket=' . rawurlencode($ticket)
        ));
        self::assertSame(200, $opened->getStatusCode(), 'o bilhete devia abrir o stream');
        $opened->getBody()->close();

        $reused = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?ticket=' . rawurlencode($ticket)
        ));
        self::assertSame(401, $reused->getStatusCode(), 'um bilhete gasto não abre nada');
    }

    /** Sem credencial nenhuma continua a não abrir: o bilhete não é uma porta lateral. */
    public function testAStreamWithoutACredentialIsStillRejected(): void
    {
        [$server] = $this->makeServerWithDatabase();

        $response = $server(new ServerRequest('GET', '/api/devices/861265061009822/stream'));

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * Um cliente que deixa de ler não pode obrigar o servidor a guardar-lhe tudo.
     *
     * Isto derrubou a produção doze vezes em catorze dias: um radar publica cerca de vinte
     * mensagens por segundo, cada envio leva o `recent()` inteiro, e escrevia-se sem olhar
     * ao que o `write()` respondia. Com o cliente a drenar mais devagar do que o dispositivo
     * produz, o buffer crescia até rebentar o limite de memória do PHP -- e o processo leva
     * consigo as ligações TCP e as subscrições MQTT de toda a gente.
     */
    public function testAStreamStopsWritingWhileTheClientIsNotDrainingAndRecoversAfterwards(): void
    {
        [$server, , $store] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'admin', 'secret');

        $response = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?access_token=' . rawurlencode($token)
        ));
        self::assertSame(200, $response->getStatusCode());

        $body = $response->getBody();
        $writes = 0;
        $body->on('data', static function () use (&$writes): void {
            $writes++;
        });

        $loop = Loop::get();
        $settle = static function (float $seconds) use ($loop): void {
            $loop->addTimer($seconds, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
        };

        // Deixa sair o snapshot inicial, que é o que o cliente recebe ao ligar-se.
        $settle(0.1);
        $afterSnapshot = $writes;
        self::assertGreaterThan(0, $afterSnapshot, 'o snapshot inicial devia ter saído');

        // O cliente pára de ler. É o que um separador em segundo plano faz.
        $body->pause();

        // A rajada tem de atravessar várias janelas de coalescência. Quarenta escritas de
        // enfiada colapsam num envio só, e um envio só não distingue nada: o teste passava
        // com e sem a correcção. Uma escrita por janela é que obriga o servidor a tentar
        // enviar cinco vezes contra um cliente que não lê.
        for ($round = 0; $round < 5; $round++) {
            $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 60 + $round]);
            // Um pouco acima do `DeviceController::STREAM_COALESCE_SECONDS`, que é 0.25.
            $settle(0.3);
        }

        // Uma escrita descobre a pausa; a partir daí não se escreve mais nada.
        self::assertSame(
            $afterSnapshot + 1,
            $writes,
            'com o cliente em pausa só a escrita que descobre a pausa pode sair'
        );

        // E quando ele volta a ler, o stream volta a servir.
        $body->resume();
        $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 99]);
        $settle(0.6);

        self::assertGreaterThan(
            $afterSnapshot + 1,
            $writes,
            'depois do drain o stream tem de voltar a escrever'
        );

        $body->close();
        self::assertSame(0, $store->updates()->listenerCount());
    }

    public function testTenantClientCanUseRecentRequestAndStreamRoutes(): void
    {
        [$server, $db, $store] = $this->makeServerWithDatabase();
        $model = $db->models->find('Vivistar', 'L08 Pro');

        self::assertIsArray($model);
        $db->modelCapabilities->replaceForModelId((int)$model['id'], ['heart_rate', 'location']);
        $store->append('861265061009822', 'telemetry', ['type' => 'heart_rate', 'value' => 72]);
        $store->append('861265061009822', 'events', ['type' => 'sos', 'status' => 'triggered']);
        $store->recordCommand('861265061009822', 'cmd-1', [
            'status' => 'waiting',
            'imei' => '861265061009822',
            'protocol' => 'vivistar',
            'requestId' => 'BPXL',
            'nativeType' => 'BPXL',
            'label' => 'Heart rate',
            'feature' => 'heart_rate',
            'expectedReplyTypes' => [],
            'requestedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]);

        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $requestResponse = $server(new ServerRequest(
            'POST',
            '/api/devices/861265061009822/requests',
            ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            json_encode(['feature' => 'heart_rate'], JSON_THROW_ON_ERROR)
        ));
        $requestBody = json_decode((string)$requestResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $requestResponse->getStatusCode(), (string)$requestResponse->getBody());
        self::assertSame('heart_rate', $requestBody['feature'] ?? null);

        $streamResponse = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009822/stream?access_token=' . rawurlencode($token)
        ));
        self::assertSame(200, $streamResponse->getStatusCode());
        self::assertSame('text/event-stream', $streamResponse->getHeaderLine('Content-Type'));

        $snapshotFrame = $this->readSseFrame($streamResponse);
        self::assertStringContainsString("event: snapshot\n", $snapshotFrame);
        $snapshot = $this->decodeSseFrame($snapshotFrame);
        self::assertSame('heart_rate', $snapshot['telemetry'][0]['type'] ?? null);
        self::assertSame('sos', $snapshot['events'][0]['type'] ?? null);
        self::assertSame('BPXL', $snapshot['commands'][0]['requestId'] ?? null);
        self::assertArrayNotHasKey('actions', $snapshot);

        $otherStream = $server(new ServerRequest(
            'GET',
            '/api/devices/861265061009833/stream?access_token=' . rawurlencode($token)
        ));
        self::assertSame(404, $otherStream->getStatusCode(), (string)$otherStream->getBody());

        $log = $this->apiLogContents();
        self::assertStringContainsString('"query":"access_token=********"', $log);
        self::assertStringNotContainsString('"query":"access_token=' . $token . '"', $log);
    }

    public function testApiRejectsBasicAuthWhileDashboardIsPublic(): void
    {
        $server = $this->makeServer();
        $basic = 'Basic ' . base64_encode('admin:secret');

        $apiResponse = $server(new ServerRequest('GET', '/api/devices', ['Authorization' => $basic]));
        self::assertSame(401, $apiResponse->getStatusCode());

        $dashboardResponse = $server(new ServerRequest('GET', '/dashboard'));
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

    private function makeServer(
        bool $apiAuthRequired = true,
        int $apiTokenTtlSeconds = 3600,
        int $apiRefreshTokenTtlSeconds = 2592000
    ): callable {
        return $this->makeServerWithDatabase(
            apiAuthRequired: $apiAuthRequired,
            apiTokenTtlSeconds: $apiTokenTtlSeconds,
            apiRefreshTokenTtlSeconds: $apiRefreshTokenTtlSeconds
        )[0];
    }

    /**
     * Devolve a cadeia inteira, e não só a dashboard: o CORS e o registo do `/api/` são
     * middleware, e é a `DashboardServerFactory` que os monta -- aqui como em produção.
     *
     * @return array{0: callable, 1: ApiDataAccess, 2: DashboardStore}
     */
    private function makeServerWithDatabase(
        ?\Hub\DeviceHubServer $hub = null,
        ?PendingDownlinkQueue $queue = null,
        bool $apiAuthRequired = true,
        int $apiTokenTtlSeconds = 3600,
        int $apiRefreshTokenTtlSeconds = 2592000
    ): array {
        $redis = new InMemoryRedisClient();
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $hitcareId = $db->companies->create('hitcare');
        $otherCareId = $db->companies->create('otherCare');
        $hitcareLicenseRef = $db->licenses->create($hitcareId, '1001', 'hitcare-license');
        $db->licenses->create($otherCareId, '2002', 'othercare-license');
        $db->licenses->create($otherCareId, '1001', 'overlapping-license-number');
        $db->apiUsers->create('admin', password_hash('secret', PASSWORD_DEFAULT), 'hub_admin', 0, true);
        $db->apiUsers->create('tenant', password_hash('tenant-secret', PASSWORD_DEFAULT), 'license_client', '1001', true, $hitcareLicenseRef);
        $db->whitelist->register('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $db->whitelist->register('861265061009833', 'Vivistar', 'L08 Pro', 'watch', 2002, '', '', 'otherCare');
        $db->whitelist->register('861265061009844', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');
        $store = new DashboardStore($redis, prefix: 'test:dashboard:http');
        $store->setDataAccess($db);
        $store->registerDevice('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $store->registerDevice('861265061009833', 'Vivistar', 'L08 Pro', 'watch', 2002, '', '', 'otherCare');
        $store->registerDevice('861265061009844', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');

        if ($hub === null) {
            $hub = $this->createMock(\Hub\DeviceHubServer::class);
            $hub->method('submitDownlink')->willReturn('sent');
        }

        $dashboard = new DashboardHttpServer(
            $store,
            new ApiTokenStore($redis, 'test:api-tokens'),
            new Whitelist($this->whitelistPath, $db->whitelist),
            $hub,
            $db,
            $apiAuthRequired,
            $apiTokenTtlSeconds,
            $apiRefreshTokenTtlSeconds
        );
        $dashboard->warmUp();

        return [DashboardServerFactory::handler($dashboard), $db, $store];
    }


    private function readSseFrame(\Psr\Http\Message\ResponseInterface $response): string
    {
        $body = $response->getBody();
        $frame = '';
        $loop = Loop::get();

        $body->on('data', static function (string $chunk) use (&$frame, $body, $loop): void {
            $frame .= $chunk;
            if (str_contains($frame, "\n\n")) {
                $body->close();
                $loop->stop();
            }
        });

        $loop->addTimer(0.2, static function () use (&$frame, $body, $loop): void {
            if (method_exists($body, 'close')) {
                $body->close();
            }
            $loop->stop();
        });

        $loop->run();

        return $frame;
    }

    /**
     * Lê o instantâneo, corre o `$write`, e continua a ler até a actualização chegar. O prazo
     * está muito abaixo do recurso periódico do stream, e por isso um frame aqui só pode ter
     * vindo do store a anunciar a escrita.
     */
    private function collectSseFramesUntilUpdate(
        \Psr\Http\Message\ResponseInterface $response,
        callable $write
    ): string {
        $body = $response->getBody();
        $frames = '';
        $loop = Loop::get();

        $body->on('data', static function (string $chunk) use (&$frames, $loop): void {
            $frames .= $chunk;
            if (str_contains($frames, 'event: update')) {
                $loop->stop();
            }
        });

        $loop->addTimer(0.05, static function () use ($write): void {
            $write();
        });
        $timeout = $loop->addTimer(2.0, static function () use ($loop): void {
            $loop->stop();
        });

        $loop->run();
        $loop->cancelTimer($timeout);

        return $frames;
    }

    private function decodeSseFrame(string $frame): array
    {
        foreach (explode("\n", trim($frame)) as $line) {
            if (str_starts_with($line, 'data: ')) {
                return json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        self::fail('SSE frame did not contain a data line.');
    }

    private function loginToken(callable $server, string $username, string $password): string
    {
        $payload = $this->loginPayload($server, $username, $password);

        return (string)($payload['token']['access_token'] ?? '');
    }

    /** @return array<string, mixed> */
    private function loginPayload(callable $server, string $username, string $password): array
    {
        $login = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $login->getStatusCode(), (string)$login->getBody());

        return json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function apiLogContents(): string
    {
        clearstatcache(true, $this->apiLogPath);
        return is_file($this->apiLogPath) ? (string)file_get_contents($this->apiLogPath) : '';
    }
}
