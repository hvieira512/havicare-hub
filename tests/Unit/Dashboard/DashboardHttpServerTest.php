<?php

namespace Tests\Unit\Dashboard;

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
use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use React\EventLoop\Loop;
use Tests\Support\MysqlDashboardTestCase;

final class DashboardHttpServerTest extends MysqlDashboardTestCase
{
    private string $whitelistPath;
    private string $apiLogPath;
    private string|false $originalLogFile;
    private string|false $originalLogLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLogFile = getenv('LOG_FILE');
        $this->originalLogLevel = getenv('LOG_LEVEL');
        $this->apiLogPath = sys_get_temp_dir() . '/hub-dashboard-api-log-' . bin2hex(random_bytes(4)) . '.log';
        putenv('LOG_FILE=' . $this->apiLogPath);
        putenv('LOG_LEVEL=info');
        Logger::reset();
        $this->whitelistPath = sys_get_temp_dir() . '/hub-dashboard-http-whitelist-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->whitelistPath, json_encode([
            '861265061009822' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '1001', 'company' => 'hitcare'],
            '861265061009833' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '2002', 'company' => 'otherCare'],
            '861265061009844' => ['supplier' => 'Vivistar', 'model' => 'L08 Pro', 'licenseId' => '0', 'company' => 'null'],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (is_file($this->whitelistPath)) {
            unlink($this->whitelistPath);
        }
        if (is_file($this->apiLogPath)) {
            unlink($this->apiLogPath);
        }
        putenv($this->originalLogFile === false ? 'LOG_FILE' : 'LOG_FILE=' . $this->originalLogFile);
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
        self::assertStringContainsString('id="telemetry"', $first);
        self::assertStringContainsString('type="module" src="main.js"', $first);
        self::assertStringContainsString('id="deviceSelectorModal"', $first);
        self::assertStringContainsString('id="deviceSelectionEmptyState"', $first);
        self::assertStringContainsString('id="discoveryDeviceSelect"', $first);
        self::assertStringContainsString('id="discoveryGenerateBtn"', $first);
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

    public function testApiResponseBodyLoggingKeepsFullBodiesWithoutEllipsis(): void
    {
        $kernel = (new \ReflectionClass(\Hub\Api\ApiKernel::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(\Hub\Api\ApiKernel::class, 'responseBodyForLog');
        $method->setAccessible(true);

        $largeBody = json_encode(['payload' => str_repeat('abc123', 800)], JSON_THROW_ON_ERROR);
        $response = new \React\Http\Message\Response(
            200,
            ['Content-Type' => 'application/json'],
            $largeBody
        );

        $preview = $method->invoke($kernel, $response);

        self::assertSame(json_decode($largeBody, true, 512, JSON_THROW_ON_ERROR), $preview);
        self::assertStringNotContainsString('...', json_encode($preview, JSON_THROW_ON_ERROR));
        self::assertGreaterThan(4096, strlen(json_encode($preview, JSON_THROW_ON_ERROR)));
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
                    'fallDetection' => ['enabled' => true],
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
                'fallDetection' => ['enabled' => true],
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
        self::assertSame(10, $body['capabilities']['contacts']['call_whitelist']['_meta']['name']['maxLength'] ?? null);
        self::assertSame(20, $body['capabilities']['contacts']['call_whitelist']['_meta']['phone']['maxLength'] ?? null);
        self::assertTrue($body['capabilities']['contacts']['call_whitelist']['_meta']['phone']['asciiOnly'] ?? false);
        self::assertTrue($body['capabilities']['contacts']['whitelist_enabled']['value']['enabled'] ?? false);
        self::assertSame('BP84', $body['capabilities']['contacts']['whitelist_enabled']['_nativeKey'] ?? null);
        self::assertSame(
            ['password' => '2468'],
            $body['capabilities']['settings_system']['device_password']['value'] ?? null
        );
        self::assertArrayNotHasKey('blood_pressure', $body['capabilities']['telemetry'] ?? []);
        self::assertArrayNotHasKey('auto_vitals_interval', $body['capabilities']['health'] ?? []);
        self::assertSame('never_reported', $body['pending']['contacts']['call_whitelist']['status'] ?? null);
        self::assertSame('never_reported', $body['pending']['contacts']['whitelist_enabled']['status'] ?? null);
        self::assertSame('never_reported', $body['pending']['settings_system']['device_password']['status'] ?? null);
        self::assertSame([], $body['transportPending'] ?? null);
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
                    'fallDetection' => ['enabled' => true],
                ],
            ], JSON_THROW_ON_ERROR)
        ));
        $putBody = json_decode((string)$put->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $put->getStatusCode(), (string)$put->getBody());
        self::assertCount(1, $submitted);
        self::assertStringContainsString('BP76', $submitted[0]['bytes']);
        self::assertSame('waiting_device', $putBody['pending']['alarms']['fall_detection']['status'] ?? null);
        self::assertSame('cfg:BP76', $putBody['transportPending'][0]['dedupeKey'] ?? null);

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
        self::assertSame('waiting_device', $detailBody['pending']['alarms']['fall_detection']['status'] ?? null);
        self::assertSame('cfg:BP76', $detailBody['transportPending'][0]['dedupeKey'] ?? null);
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
                'reminderSettings' => '11:25-1-3-1010',
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => 'data:audio/wav;base64,' . $this->sampleWavBase64(),
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
                        'custom' => '1010',
                    ],
                ],
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => 'data:audio/wav;base64,' . $this->sampleWavBase64(),
                'voiceMimeType' => 'audio/wav',
            ],
            $body['capabilities']['alarms']['medication_reminders']['value'] ?? null
        );
        self::assertSame('takePills', $body['capabilities']['alarms']['medication_reminders']['_nativeKey'] ?? null);
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
                'reminderSettings' => '11:25-1-2-14:30-0-1-18:00-1-3-1010',
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
                    ['time' => '18:00', 'enabled' => true, 'frequency' => 3, 'custom' => '1010'],
                ],
                'number' => 3,
                'reminderText' => 'meds',
                'voiceData' => '',
                'voiceMimeType' => '',
            ],
            $body['capabilities']['alarms']['medication_reminders']['value'] ?? null
        );
        self::assertSame('takePills', $body['capabilities']['alarms']['medication_reminders']['_nativeKey'] ?? null);
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
    ): DashboardHttpServer
    {
        return $this->makeServerWithDatabase(
            apiAuthRequired: $apiAuthRequired,
            apiTokenTtlSeconds: $apiTokenTtlSeconds,
            apiRefreshTokenTtlSeconds: $apiRefreshTokenTtlSeconds
        )[0];
    }

    /**
     * @return array{0: DashboardHttpServer, 1: ApiDataAccess, 2: DashboardStore}
     */
    private function makeServerWithDatabase(
        ?\Hub\DeviceHubServer $hub = null,
        ?PendingDownlinkQueue $queue = null,
        bool $apiAuthRequired = true,
        int $apiTokenTtlSeconds = 3600,
        int $apiRefreshTokenTtlSeconds = 2592000
    ): array
    {
        $redis = new InMemoryRedisClientForDashboardHttpServerTest();
        $db = ApiDataAccess::fromDatabase($this->createDashboardDatabase());
        $db->apiUsers->create('tenant', password_hash('tenant-secret', PASSWORD_DEFAULT), 'license_client', '1001', true);
        $hitcareId = $db->companies->create('hitcare');
        $otherCareId = $db->companies->create('otherCare');
        $db->licenses->create($hitcareId, '1001', 'hitcare-license');
        $db->licenses->create($otherCareId, '2002', 'othercare-license');
        $store = new DashboardStore($redis, prefix: 'test:dashboard:http');
        $store->setDataAccess($db);
        $store->registerDevice('861265061009822', 'Vivistar', 'L08 Pro', 'watch', 1001, '', '', 'hitcare');
        $store->registerDevice('861265061009833', 'Vivistar', 'L08 Pro', 'watch', 2002, '', '', 'otherCare');
        $store->registerDevice('861265061009844', 'Vivistar', 'L08 Pro', 'watch', 0, '', '', 'null');

        if ($hub === null) {
            $hub = $this->createMock(\Hub\DeviceHubServer::class);
            $hub->method('submitDownlink')->willReturn('sent');
        }

        $server = new DashboardHttpServer(
            $store,
            new ApiTokenStore($redis, 'test:api-tokens'),
            new Whitelist($this->whitelistPath),
            $hub,
            $queue,
            $db,
            'admin',
            'secret',
            'tenant',
            'tenant-secret',
            $apiAuthRequired,
            $apiTokenTtlSeconds,
            $apiRefreshTokenTtlSeconds
        );

        return [$server, $db, $store];
    }

    private function sampleWavBase64(): string
    {
        $sampleRate = 8000;
        $channels = 1;
        $bitsPerSample = 16;
        $data = str_repeat(pack('v', 0), 800);

        $byteRate = (int)($sampleRate * $channels * ($bitsPerSample / 8));
        $blockAlign = (int)($channels * ($bitsPerSample / 8));
        $header = 'RIFF'
            . pack('V', 36 + strlen($data))
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)
            . pack('v', 1)
            . pack('v', $channels)
            . pack('V', $sampleRate)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', $bitsPerSample)
            . 'data'
            . pack('V', strlen($data));

        return base64_encode($header . $data);
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

    private function decodeSseFrame(string $frame): array
    {
        foreach (explode("\n", trim($frame)) as $line) {
            if (str_starts_with($line, 'data: ')) {
                return json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        self::fail('SSE frame did not contain a data line.');
    }

    private function loginToken(DashboardHttpServer $server, string $username, string $password): string
    {
        $login = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(200, $login->getStatusCode(), (string)$login->getBody());
        $payload = json_decode((string)$login->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return (string)($payload['token']['access_token'] ?? '');
    }

    private function apiLogContents(): string
    {
        clearstatcache(true, $this->apiLogPath);
        return is_file($this->apiLogPath) ? (string)file_get_contents($this->apiLogPath) : '';
    }
}

final class InMemoryRedisClientForDashboardHttpServerTest implements ClientInterface
{
    /** @var array<string, array<string, bool>> */
    private array $sets = [];

    /** @var array<string, array<string, string>> */
    private array $hashes = [];

    /** @var array<string, array<int, string>> */
    private array $lists = [];

    /** @var array<string, string> */
    private array $strings = [];

    /** @var array<string, int> */
    private array $stringExpiresAt = [];

    /** @var array<string, array<string, float>> */
    private array $sortedSets = [];

    public function getCommandFactory()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function getOptions()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function connect()
    {
    }

    public function disconnect()
    {
    }

    public function getConnection()
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function createCommand($method, $arguments = [])
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function executeCommand(CommandInterface $command)
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function pipeline(callable $callback): void
    {
        $callback($this);
    }

    public function __call($method, $arguments)
    {
        return match (strtolower((string)$method)) {
            'sadd' => $this->sadd((string)$arguments[0], (string)$arguments[1]),
            'srem' => $this->srem((string)$arguments[0], (string)$arguments[1]),
            'smembers' => $this->smembers((string)$arguments[0]),
            'hmset' => $this->hmset((string)$arguments[0], $arguments[1]),
            'hgetall' => $this->hgetall((string)$arguments[0]),
            'hset' => $this->hset((string)$arguments[0], (string)$arguments[1], (string)$arguments[2]),
            'hdel' => $this->hdel((string)$arguments[0], $arguments[1]),
            'hget' => $this->hget((string)$arguments[0], (string)$arguments[1]),
            'lpush' => $this->lpush((string)$arguments[0], $arguments[1]),
            'ltrim' => $this->ltrim((string)$arguments[0], (int)$arguments[1], (int)$arguments[2]),
            'lrange' => $this->lrange((string)$arguments[0], (int)$arguments[1], (int)$arguments[2]),
            'lrem' => $this->lrem((string)$arguments[0], (int)$arguments[1], (string)$arguments[2]),
            'zadd' => $this->zadd((string)$arguments[0], $arguments[1]),
            'zrem' => $this->zrem((string)$arguments[0], $arguments[1]),
            'zrangebyscore' => $this->zrangebyscore((string)$arguments[0], (string)$arguments[1], (string)$arguments[2]),
            'setex' => $this->setex((string)$arguments[0], (int)$arguments[1], (string)$arguments[2]),
            'get' => $this->get((string)$arguments[0]),
            'del' => $this->del($arguments[0]),
            default => throw new \BadMethodCallException("Redis method {$method} is not implemented"),
        };
    }

    private function sadd(string $key, string $member): int
    {
        $exists = isset($this->sets[$key][$member]);
        $this->sets[$key][$member] = true;

        return $exists ? 0 : 1;
    }

    private function srem(string $key, string $member): int
    {
        $exists = isset($this->sets[$key][$member]);
        unset($this->sets[$key][$member]);

        return $exists ? 1 : 0;
    }

    private function smembers(string $key): array
    {
        return array_keys($this->sets[$key] ?? []);
    }

    private function hmset(string $key, array $dictionary): string
    {
        $this->hashes[$key] = array_merge($this->hashes[$key] ?? [], array_map('strval', $dictionary));

        return 'OK';
    }

    private function hgetall(string $key): array
    {
        return $this->hashes[$key] ?? [];
    }

    private function hset(string $key, string $field, string $value): int
    {
        $exists = array_key_exists($field, $this->hashes[$key] ?? []);
        $this->hashes[$key][$field] = $value;

        return $exists ? 0 : 1;
    }

    private function hdel(string $key, $fields): int
    {
        $fields = is_array($fields) ? $fields : [$fields];
        $deleted = 0;
        foreach ($fields as $field) {
            $field = (string)$field;
            if (isset($this->hashes[$key][$field])) {
                unset($this->hashes[$key][$field]);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function hget(string $key, string $field): ?string
    {
        return $this->hashes[$key][$field] ?? null;
    }

    private function lpush(string $key, $values): int
    {
        $values = is_array($values) ? array_values(array_map('strval', $values)) : [(string)$values];
        $this->lists[$key] = array_merge($values, $this->lists[$key] ?? []);

        return count($this->lists[$key]);
    }

    private function ltrim(string $key, int $start, int $stop): string
    {
        $list = $this->lists[$key] ?? [];
        if ($stop < 0) {
            $stop = count($list) + $stop;
        }
        $length = max(0, $stop - $start + 1);
        $this->lists[$key] = array_slice($list, $start, $length);

        return 'OK';
    }

    private function lrange(string $key, int $start, int $stop): array
    {
        $list = $this->lists[$key] ?? [];
        if ($stop < 0) {
            $stop = count($list) + $stop;
        }
        $length = max(0, $stop - $start + 1);

        return array_slice($list, $start, $length);
    }

    private function lrem(string $key, int $count, string $value): int
    {
        $list = $this->lists[$key] ?? [];
        $removed = 0;
        $result = [];

        foreach ($list as $item) {
            if ($item === $value && ($count === 0 || $removed < abs($count))) {
                $removed++;
                continue;
            }
            $result[] = $item;
        }

        $this->lists[$key] = $result;

        return $removed;
    }

    private function zadd(string $key, array $members): int
    {
        $added = 0;
        foreach ($members as $member => $score) {
            if (!isset($this->sortedSets[$key][(string)$member])) {
                $added++;
            }
            $this->sortedSets[$key][(string)$member] = (float)$score;
        }

        return $added;
    }

    private function zrem(string $key, $members): int
    {
        $members = is_array($members) ? $members : [$members];
        $deleted = 0;
        foreach ($members as $member) {
            $member = (string)$member;
            if (isset($this->sortedSets[$key][$member])) {
                unset($this->sortedSets[$key][$member]);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function zrangebyscore(string $key, string $min, string $max): array
    {
        $items = $this->sortedSets[$key] ?? [];
        $lower = $min === '-inf' ? -INF : (float)$min;
        $upper = $max === '+inf' ? INF : (float)$max;
        $filtered = array_filter($items, static fn(float $score): bool => $score >= $lower && $score <= $upper);
        asort($filtered, SORT_NUMERIC);

        return array_keys($filtered);
    }

    private function setex(string $key, int $seconds, string $value): string
    {
        $this->strings[$key] = $value;
        $this->stringExpiresAt[$key] = time() + max(1, $seconds);

        return 'OK';
    }

    private function get(string $key): ?string
    {
        if (isset($this->stringExpiresAt[$key]) && $this->stringExpiresAt[$key] <= time()) {
            unset($this->strings[$key], $this->stringExpiresAt[$key]);
            return null;
        }

        return $this->strings[$key] ?? null;
    }

    private function del($keys): int
    {
        $keys = is_array($keys) ? $keys : [$keys];
        $deleted = 0;
        foreach ($keys as $key) {
            $key = (string)$key;
            foreach (['hashes', 'lists', 'strings', 'sets', 'sortedSets'] as $bucket) {
                if (isset($this->{$bucket}[$key])) {
                    unset($this->{$bucket}[$key]);
                    $deleted++;
                }
            }
            unset($this->stringExpiresAt[$key]);
        }

        return $deleted;
    }
}
